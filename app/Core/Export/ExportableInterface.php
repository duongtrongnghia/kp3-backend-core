<?php

declare(strict_types=1);

namespace App\Core\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for module export capability.
 *
 * Modules implement this interface and register with ExportRegistry.
 * Core ExportService handles streaming, chunking, and format output.
 *
 * Usage:
 *   class ExportOrders implements ExportableInterface {
 *       public function exportQuery(array $filters): Builder { ... }
 *       public function exportColumns(): array { ... }
 *       public function exportFileName(): string { ... }
 *   }
 */
interface ExportableInterface
{
    /**
     * Build the query for export, with optional filters applied.
     *
     * @param  array<string, mixed>  $filters  Key-value filters from request (status, date_from, date_to, etc.)
     * @return Builder<Model>
     */
    public function exportQuery(array $filters): Builder;

    /**
     * Define export columns.
     *
     * @return array<int, array{key: string, label: string, formatter?: callable}> Column definitions
     */
    public function exportColumns(): array;

    /**
     * Generate the export filename (without extension).
     */
    public function exportFileName(): string;
}
