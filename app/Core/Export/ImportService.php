<?php

declare(strict_types=1);

namespace App\Core\Export;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Core import service — handles CSV parsing, validation, and chunked processing.
 */
class ImportService
{
    /**
     * Import a CSV file using an ImportableInterface implementation.
     */
    public function csv(ImportableInterface $importer, UploadedFile $file): ImportResult
    {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            return new ImportResult(0, 0, [['row' => 0, 'field' => null, 'message' => 'Cannot read uploaded file.']]);
        }

        $handle = fopen($realPath, 'r');
        if ($handle === false) {
            return new ImportResult(0, 0, [['row' => 0, 'field' => null, 'message' => 'Cannot open uploaded file.']]);
        }

        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) {
            rewind($handle);
        }

        // Read header row
        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return new ImportResult(0, 0, [['row' => 0, 'field' => null, 'message' => 'Empty file or invalid CSV.']]);
        }

        // Normalize headers (trim whitespace, cast null to empty string defensively)
        $headers = array_map(fn (mixed $h): string => trim((string) $h), $headers);

        $rules = $importer->importRules();
        $chunkSize = $importer->importChunkSize();
        $success = 0;
        $failed = 0;
        $errors = [];
        $rowNumber = 1;
        $chunk = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $data = array_combine($headers, array_pad($row, count($headers), null));

            // Validate
            $validator = Validator::make($data, $rules);
            if ($validator->fails()) {
                $failed++;
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = ['row' => $rowNumber, 'message' => $message];
                }
                // Cap error collection to prevent memory issues
                if (count($errors) > 500) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Too many errors — import stopped.'];
                    break;
                }

                continue;
            }

            $chunk[] = $data;

            // Process chunk
            if (count($chunk) >= $chunkSize) {
                $success += $this->processChunk($importer, $chunk, $errors, $failed);
                $chunk = [];
            }
        }

        // Process remaining
        if (! empty($chunk)) {
            $success += $this->processChunk($importer, $chunk, $errors, $failed);
        }

        fclose($handle);

        return new ImportResult($success, $failed, $errors);
    }

    /**
     * @param  array<int, array<string, string|null>>  $chunk
     * @param  array<int, array{row: int|null, message: string, field?: string|null}>  $errors
     */
    private function processChunk(ImportableInterface $importer, array $chunk, array &$errors, int &$failed): int
    {
        $success = 0;
        foreach ($chunk as $row) {
            try {
                $importer->importRow($row);
                $success++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = ['row' => null, 'message' => $e->getMessage()];
            }
        }

        return $success;
    }
}
