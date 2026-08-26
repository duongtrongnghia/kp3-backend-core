<?php

declare(strict_types=1);

namespace App\Core\DataTable;

/**
 * Static registry for DataTable extension metadata.
 *
 * Modules self-register filters, columns, and search scopes
 * without knowing which pages will consume them.
 * Theme bindings.json decides which extensions are active per page.
 *
 * Filter metadata schema:
 *   DataTableRegistry::register('category_filter', [
 *       'label'    => 'Danh mục',
 *       'type'     => 'relation',      // relation | enum | boolean | custom
 *       'relation' => 'categories',    // relation name (for auto-query)
 *       'entity'   => 'category',      // EntityRegistry key (for FE search API)
 *       'multiple' => true,
 *   ]);
 *
 * Search scope metadata:
 *   DataTableRegistry::registerSearchScope('product_name_search', [
 *       'entity' => 'product',
 *       'fields' => ['name'],
 *   ]);
 */
class DataTableRegistry
{
    /** @var array<string, array<string, mixed>> id => metadata */
    private static array $items = [];

    /** @var array<string, array<string, mixed>> id => search scope metadata */
    private static array $searchScopes = [];

    /**
     * Register a filter, column, or action extension.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function register(string $id, array $metadata): void
    {
        self::$items[$id] = $metadata;
    }

    /**
     * Register a search scope extension.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function registerSearchScope(string $id, array $metadata): void
    {
        self::$searchScopes[$id] = $metadata;
    }

    /**
     * Get metadata by ID. Returns null if not registered.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        return self::$items[$id] ?? null;
    }

    /**
     * Get search scope by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function getSearchScope(string $id): ?array
    {
        return self::$searchScopes[$id] ?? null;
    }

    /**
     * Get multiple items by IDs. Skips unregistered IDs silently.
     *
     * @param  string[]  $ids
     * @return array<string, array<string, mixed>>
     */
    public static function getByIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (isset(self::$items[$id])) {
                $result[$id] = self::$items[$id];
            }
        }

        return $result;
    }

    /**
     * Get multiple search scopes by IDs.
     *
     * @param  string[]  $ids
     * @return array<string, array<string, mixed>>
     */
    public static function getSearchScopesByIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (isset(self::$searchScopes[$id])) {
                $result[$id] = self::$searchScopes[$id];
            }
        }

        return $result;
    }

    /**
     * Get all registered items.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$items;
    }

    /**
     * Get all registered search scopes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function allSearchScopes(): array
    {
        return self::$searchScopes;
    }

    /**
     * Check if an item is registered.
     */
    public static function has(string $id): bool
    {
        return isset(self::$items[$id]);
    }

    /**
     * Reset — for testing only.
     */
    public static function reset(): void
    {
        self::$items = [];
        self::$searchScopes = [];
    }
}
