<?php

declare(strict_types=1);

namespace App\Core\Registry;

use App\Core\Export\ExportableInterface;
use App\Core\Export\ImportableInterface;

/**
 * Static registry for module export/import capabilities.
 *
 * Modules register in bootModule():
 *   ExportRegistry::register('order', ExportOrders::class);
 *   ExportRegistry::registerImporter('product', ImportProducts::class);
 *
 * Usage:
 *   ExportRegistry::getExporter('order');   // ExportableInterface instance
 *   ExportRegistry::available();            // ['order', 'product', 'user', ...]
 */
class ExportRegistry
{
    /** @var array<string, string> type → FQCN (verified implements ExportableInterface at register time) */
    private static array $exporters = [];

    /** @var array<string, string> type → FQCN (verified implements ImportableInterface at register time) */
    private static array $importers = [];

    /**
     * @param  class-string<ExportableInterface>  $exporterClass
     */
    public static function register(string $type, string $exporterClass): void
    {
        self::$exporters[$type] = $exporterClass;
    }

    /**
     * @param  class-string<ImportableInterface>  $importerClass
     */
    public static function registerImporter(string $type, string $importerClass): void
    {
        self::$importers[$type] = $importerClass;
    }

    public static function getExporter(string $type): ?ExportableInterface
    {
        $class = self::$exporters[$type] ?? null;

        return $class ? app($class) : null;
    }

    public static function getImporter(string $type): ?ImportableInterface
    {
        $class = self::$importers[$type] ?? null;

        return $class ? app($class) : null;
    }

    /**
     * List available export types.
     *
     * @return string[]
     */
    public static function available(): array
    {
        return array_keys(self::$exporters);
    }

    /**
     * List available import types.
     *
     * @return string[]
     */
    public static function importable(): array
    {
        return array_keys(self::$importers);
    }

    public static function reset(): void
    {
        self::$exporters = [];
        self::$importers = [];
    }
}
