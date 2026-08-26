<?php

declare(strict_types=1);

namespace App\Core\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Core export service — handles streaming CSV output for any ExportableInterface.
 *
 * Modules implement ExportableInterface (query, columns, filename).
 * This service handles: BOM, header row, chunked streaming, column formatters.
 */
class ExportService
{
    /**
     * Stream a CSV export from an ExportableInterface implementation.
     *
     * @param  ExportableInterface  $exporter  Module's exporter implementation
     * @param  array<string, mixed>  $filters  Request filters to pass to exportQuery()
     * @param  int  $chunkSize  Rows per chunk (default 1000)
     */
    public function csv(ExportableInterface $exporter, array $filters = [], int $chunkSize = 1000): StreamedResponse
    {
        $columns = $exporter->exportColumns();
        $query = $exporter->exportQuery($filters);
        $fileName = $exporter->exportFileName().'_'.date('Y-m-d_H-i-s').'.csv';

        return response()->streamDownload(function () use ($query, $columns, $chunkSize) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // BOM for Excel UTF-8 compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Header row — sanitize labels to block formula injection (CWE-1236)
            fputcsv($handle, CsvSanitizer::escapeRow(array_column($columns, 'label')));

            // Data rows — chunked for memory efficiency
            $query->chunk($chunkSize, function ($rows) use ($handle, $columns) {
                foreach ($rows as $row) {
                    $csvRow = [];
                    foreach ($columns as $col) {
                        $value = data_get($row, $col['key']);
                        // isset check is sufficient: formatter key presence implies callable in ExportableInterface contract
                        if (isset($col['formatter'])) {
                            /** @var callable $formatter */
                            $formatter = $col['formatter'];
                            $value = $formatter($value, $row);
                        }
                        $csvRow[] = $value;
                    }
                    fputcsv($handle, CsvSanitizer::escapeRow($csvRow));
                }
            });

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    /**
     * Stream a JSON Lines export.
     *
     * @param  array<string, mixed>  $filters
     */
    public function jsonLines(ExportableInterface $exporter, array $filters = [], int $chunkSize = 1000): StreamedResponse
    {
        $columns = $exporter->exportColumns();
        $query = $exporter->exportQuery($filters);
        $fileName = $exporter->exportFileName().'_'.date('Y-m-d_H-i-s').'.jsonl';

        return response()->streamDownload(function () use ($query, $columns, $chunkSize) {
            $query->chunk($chunkSize, function ($rows) use ($columns) {
                foreach ($rows as $row) {
                    $data = [];
                    foreach ($columns as $col) {
                        $value = data_get($row, $col['key']);
                        if (isset($col['formatter'])) {
                            /** @var callable $formatter */
                            $formatter = $col['formatter'];
                            $value = $formatter($value, $row);
                        }
                        $data[$col['key']] = $value;
                    }
                    echo json_encode($data, JSON_UNESCAPED_UNICODE)."\n";
                }
            });
        }, $fileName, ['Content-Type' => 'application/x-ndjson']);
    }
}
