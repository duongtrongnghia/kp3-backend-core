<?php

declare(strict_types=1);

namespace App\Core\Export;

/**
 * Contract for module import capability.
 *
 * Modules implement this interface and register with ExportRegistry.
 * Core ImportService handles chunked reading, validation, and error reporting.
 */
interface ImportableInterface
{
    /**
     * Laravel validation rules for each row.
     *
     * @return array<string, mixed> Rules keyed by column name
     */
    public function importRules(): array;

    /**
     * Process a single validated row.
     *
     * @param  array<string, string|null>  $row  Validated row data keyed by column name
     */
    public function importRow(array $row): void;

    /**
     * Number of rows to process per chunk.
     */
    public function importChunkSize(): int;
}
