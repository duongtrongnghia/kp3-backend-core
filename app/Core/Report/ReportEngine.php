<?php

declare(strict_types=1);

namespace App\Core\Report;

use App\Core\Registry\EntityRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cross-module reporting engine using Base+Dimension compose pattern.
 *
 * Module registers 'reports' callable in EntityRegistry → returns report definitions.
 * ReportEngine composes base query + dimensions + date filters + grouping.
 *
 * Usage:
 *   // Module registers in bootModule():
 *   EntityRegistry::register('order', [
 *       'reports' => fn() => [
 *           'revenue' => [
 *               'label' => 'Doanh thu',
 *               'base'  => fn($filters) => Order::query()->selectRaw('SUM(total) as value, COUNT(*) as count')
 *                   ->when($filters['from'] ?? null, fn($q, $d) => $q->where('created_at', '>=', $d))
 *                   ->when($filters['to'] ?? null, fn($q, $d) => $q->where('created_at', '<=', $d)),
 *               'dimensions' => ['category', 'tag'],
 *           ],
 *       ],
 *   ]);
 *
 *   // Run report:
 *   ReportEngine::run('order', 'revenue', ['from' => '2026-01-01', 'group_by' => ['month']]);
 */
class ReportEngine
{
    /** @var string[] */
    private static array $builtInDimensions = ['month', 'week', 'day'];

    /**
     * Run a report by type and key.
     *
     * @param  string  $type  Entity type (e.g. 'order')
     * @param  string  $reportKey  Report key (e.g. 'revenue')
     * @param  array<string, mixed>  $filters  { from?, to?, group_by?: string[], limit?: int }
     * @return array<string, mixed> { data: array, meta: array }
     */
    public static function run(string $type, string $reportKey, array $filters = []): array
    {
        $reports = self::getReports($type);

        if (! $reports || ! isset($reports[$reportKey])) {
            return ['data' => [], 'meta' => ['error' => "Report [{$type}.{$reportKey}] not found."]];
        }

        $report = $reports[$reportKey];

        // Check required modules
        if (! empty($report['requires'])) {
            foreach ((array) $report['requires'] as $required) {
                if (! function_exists('is_module_loaded') || ! is_module_loaded(ucfirst((string) $required))) {
                    return ['data' => [], 'meta' => ['error' => "Required module [{$required}] not loaded."]];
                }
            }
        }

        // Build base query
        $query = ($report['base'])($filters);

        // Apply built-in time dimensions
        /** @var string[] $groupBy */
        $groupBy = $filters['group_by'] ?? [];
        foreach ($groupBy as $dim) {
            if (in_array($dim, self::$builtInDimensions, true)) {
                $query = self::applyTimeDimension($query, $dim);
            }
        }

        // Apply module dimensions (e.g. category, tag)
        foreach ($groupBy as $dim) {
            if (! in_array($dim, self::$builtInDimensions, true)) {
                $dimensionCallable = EntityRegistry::get($dim)['dimension'] ?? null;
                if ($dimensionCallable && is_callable($dimensionCallable)) {
                    $query = $dimensionCallable($query, $type);
                    $query->addSelect("{$dim}s.name as {$dim}_name")
                        ->groupBy("{$dim}s.name");
                }
            }
        }

        // Apply limit
        $limit = min((int) ($filters['limit'] ?? 100), 500);
        $query->limit($limit);

        // Order by time dimension if present
        foreach ($groupBy as $dim) {
            if (in_array($dim, self::$builtInDimensions, true)) {
                $query->orderBy('period');
                break;
            }
        }

        $data = $query->get()->toArray();

        return [
            'data' => $data,
            'meta' => [
                'type' => $type,
                'key' => $reportKey,
                'filters' => $filters,
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Get available reports (optionally filtered by user permissions).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function available(): array
    {
        $result = [];

        foreach (EntityRegistry::all() as $type => $config) {
            $reports = self::getReports($type);
            if (! $reports) {
                continue;
            }

            foreach ($reports as $key => $report) {
                $result[] = [
                    'type' => $type,
                    'key' => $key,
                    'label' => $report['label'] ?? $key,
                    'dimensions' => array_merge(
                        self::$builtInDimensions,
                        $report['dimensions'] ?? []
                    ),
                    'requires' => $report['requires'] ?? [],
                ];
            }
        }

        return $result;
    }

    /**
     * Get reports array from EntityRegistry for a type.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function getReports(string $type): ?array
    {
        $config = EntityRegistry::get($type);
        if (! $config || ! isset($config['reports']) || ! is_callable($config['reports'])) {
            return null;
        }

        return ($config['reports'])();
    }

    /**
     * Apply built-in time dimension grouping.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function applyTimeDimension(Builder $query, string $dimension): Builder
    {
        $expr = match ($dimension) {
            'day' => DB::raw('DATE(created_at) as period'),
            'week' => DB::raw('DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY)) as period'),
            'month' => DB::raw("DATE_FORMAT(created_at, '%Y-%m') as period"),
            // Default covers any unrecognised dimension — should never occur since callers
            // only pass values from $builtInDimensions, but satisfies PHPStan's match exhaustion.
            default => DB::raw('DATE(created_at) as period'),
        };

        return $query->addSelect($expr)->groupBy('period');
    }
}
