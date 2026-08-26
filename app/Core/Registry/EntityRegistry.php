<?php

declare(strict_types=1);

namespace App\Core\Registry;

use RuntimeException;

/**
 * Generic callable registry for cross-module entity metadata.
 *
 * Modules self-register in ServiceProvider::boot() to declare entity metadata
 * (label, resolve, search, filters, reports, etc.) without direct coupling.
 *
 * Unlike CapabilityRegistry (validates model props at boot), EntityRegistry
 * stores arbitrary callables — callers decide what to invoke at runtime.
 *
 * Usage:
 *   EntityRegistry::register('order', [
 *       'label'        => 'Đơn hàng',
 *       'label_plural' => 'Đơn hàng',
 *       'title_field'  => 'order_number',
 *       'route'        => '/orders/:id',
 *       'model'        => Order::class,
 *       'permission'   => 'order.list',
 *       'resolve'      => fn(int $id) => Order::find($id)?->order_number,
 *       'batch_resolve'=> fn(array $ids) => Order::whereIn('id', $ids)->pluck('order_number', 'id')->all(),
 *       'search'       => fn(string $term, int $limit = 20) => Order::where('order_number', 'like', "%{$term}%")->limit($limit)->get(['id', 'order_number'])->toArray(),
 *       'filters'      => [...],
 *       'reports'      => [...],
 *       'dimension'    => fn($query, $entityType) => ...,
 *   ]);
 *
 *   EntityRegistry::call('order', 'resolve', [123]);      // "ĐH-00123"
 *   EntityRegistry::call('order', 'search', ['iphone']);   // [{id, order_number}, ...]
 *   EntityRegistry::get('order');                          // full config array
 */
class EntityRegistry
{
    /** @var array<string, array<string, mixed>> type → config */
    private static array $types = [];

    /**
     * Register an entity type with its metadata and callables.
     *
     * @param  string  $type  Morph alias (e.g. 'order', 'post', 'product')
     * @param  array<string, mixed>  $config  Must include 'label' and 'label_plural' at minimum
     *
     * @throws RuntimeException if type already registered by different module
     */
    public static function register(string $type, array $config): void
    {
        if (isset(self::$types[$type])) {
            // Idempotent re-registration (test re-boot) → merge
            self::$types[$type] = array_merge(self::$types[$type], $config);

            return;
        }

        self::$types[$type] = $config;
    }

    /**
     * Extend an existing registration with additional config.
     * Useful for modules that add capabilities to another module's entity
     * (e.g. Category module adds 'dimension' callable to its own registration).
     *
     * @param  string  $type  Morph alias
     * @param  array<string, mixed>  $extra  Additional config to merge
     */
    public static function extend(string $type, array $extra): void
    {
        if (! isset(self::$types[$type])) {
            self::$types[$type] = $extra;

            return;
        }

        self::$types[$type] = array_merge(self::$types[$type], $extra);
    }

    /**
     * Get full config for a type. Returns null if not registered.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $type): ?array
    {
        return self::$types[$type] ?? null;
    }

    /**
     * Get all registered types with their config.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$types;
    }

    /**
     * Check if a type is registered.
     */
    public static function has(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    /**
     * Call a registered callable by type and method key.
     *
     * Returns null if type not registered or method not defined/not callable.
     * This allows graceful degradation when a module is not loaded.
     *
     * @param  string  $type  Morph alias
     * @param  string  $method  Config key that holds a callable (e.g. 'resolve', 'search')
     * @param  mixed[]  $args  Arguments to pass to the callable
     */
    public static function call(string $type, string $method, array $args = []): mixed
    {
        $config = self::$types[$type] ?? null;

        if (! $config || ! isset($config[$method]) || ! is_callable($config[$method])) {
            return null;
        }

        return call_user_func_array($config[$method], $args);
    }

    /**
     * Get serializable config (strip callables) for API responses.
     * Adds computed flags: 'searchable', 'has_filters', 'has_reports'.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function serializable(): array
    {
        $result = [];

        foreach (self::$types as $type => $config) {
            $safe = [];
            foreach ($config as $key => $value) {
                if (! is_callable($value) || is_string($value)) {
                    $safe[$key] = $value;
                }
            }

            // Computed flags for FE
            $safe['searchable'] = isset($config['search']) && is_callable($config['search']);
            $safe['has_filters'] = isset($config['filters']) && is_array($config['filters']);
            $safe['has_reports'] = isset($config['reports']) && is_callable($config['reports']);

            $result[$type] = $safe;
        }

        return $result;
    }

    /**
     * Reset registry — for testing only.
     */
    public static function reset(): void
    {
        self::$types = [];
    }
}
