<?php

declare(strict_types=1);

namespace App\Core\Registry;

/**
 * Static registry of loaded (active) modules.
 *
 * Populated by ModuleServiceProvider::register() during boot.
 * Modules and services check is_module_loaded() helper (or this class directly)
 * to conditionally load relations, eager-load, etc.
 *
 * Pattern consistent with CapabilityRegistry, WidgetTypeRegistry, ShippingCarrierRegistry.
 *
 * Usage:
 *   ModuleRegistry::isLoaded('Category');  // true if theme requires Category
 *   ModuleRegistry::getLoaded();           // ['Post', 'Category', 'Product', ...]
 */
class ModuleRegistry
{
    /** @var array<string, true> module name → true */
    private static array $loaded = [];

    /**
     * Register a module as loaded. Called during ModuleServiceProvider::register().
     * Stored case-sensitively as registered by the scanner (PascalCase per modules/ dir
     * convention), but lookups via isLoaded() are case-insensitive to prevent caller
     * casing mismatches from silently disabling features (V16.1 incident: callers used
     * 'customer' lowercase while registry stored 'Customer' → all checks returned false).
     */
    public static function register(string $moduleName): void
    {
        self::$loaded[$moduleName] = true;
    }

    /**
     * Check if a module is loaded. Case-insensitive — accepts 'Customer', 'customer',
     * or 'CUSTOMER' identically to prevent the V16.1 silent-disable trap.
     */
    public static function isLoaded(string $moduleName): bool
    {
        $needle = strtolower($moduleName);
        foreach (array_keys(self::$loaded) as $registered) {
            if (strtolower($registered) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all loaded module names (case as registered).
     *
     * @return string[]
     */
    public static function getLoaded(): array
    {
        return array_keys(self::$loaded);
    }

    /**
     * Reset — for testing only.
     */
    public static function reset(): void
    {
        self::$loaded = [];
    }
}
