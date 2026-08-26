<?php

declare(strict_types=1);

use App\Core\Cache\CacheService;
use App\Core\Hooks\HookManager;
use App\Core\Meta\EntityMetaService;
use App\Core\Registry\EntityRegistry;
use App\Core\Registry\ModuleRegistry;
use App\Core\Registry\NotificationRegistry;
use App\Core\Services\ModuleSettingService;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Core helper functions — the public DX surface of the module engine.
| Autoloaded via composer.json "files". Framework-agnostic (no tenant/theme).
|--------------------------------------------------------------------------
*/

// ─── Hooks (actions + filters) ───

if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        HookManager::doAction($hook, ...$args);
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return HookManager::applyFilters($hook, $value, ...$args);
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook, callable|string $callback, int $priority = 10, string $type = HookManager::TYPE_SYNC): void
    {
        HookManager::addAction($hook, $callback, $priority, $type);
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable|string $callback, int $priority = 10): void
    {
        HookManager::addFilter($hook, $callback, $priority);
    }
}

if (! function_exists('on_rollback')) {
    function on_rollback(callable $callback): void
    {
        HookManager::onRollback($callback);
    }
}

// ─── Module registry + settings ───

if (! function_exists('is_module_loaded')) {
    function is_module_loaded(string $module): bool
    {
        return ModuleRegistry::isLoaded($module);
    }
}

if (! function_exists('module_setting')) {
    /**
     * Get module setting: DB → config("module.{name}.{key}") → $default.
     */
    function module_setting(string $module, string $key, mixed $default = null): mixed
    {
        $value = app(ModuleSettingService::class)->get($module, $key);

        if ($value !== null) {
            return $value;
        }

        return config("module.{$module}.{$key}", $default);
    }
}

if (! function_exists('set_module_setting')) {
    function set_module_setting(string $module, string $key, mixed $value, string $type = 'string'): void
    {
        app(ModuleSettingService::class)->set($module, $key, $value, $type);
    }
}

// ─── Model meta (HasMeta trait) ───

if (! function_exists('get_meta')) {
    function get_meta(object $model, string $key, mixed $default = null): mixed
    {
        if (! method_exists($model, 'getMeta')) {
            return $default;
        }

        return $model->getMeta($key, $default);
    }
}

if (! function_exists('set_meta')) {
    function set_meta(object $model, string $key, mixed $value, string $type = 'string'): void
    {
        if (method_exists($model, 'setMeta')) {
            $model->setMeta($key, $value, $type);
        }
    }
}

// ─── Entity meta (universal, works for ANY Eloquent model, no trait needed) ───

if (! function_exists('meta_set')) {
    function meta_set(Model $entity, string $key, mixed $value, string $type = 'string'): void
    {
        app(EntityMetaService::class)->set($entity, $key, $value, $type);
    }
}

if (! function_exists('meta_get')) {
    function meta_get(Model $entity, string $key, mixed $default = null): mixed
    {
        return app(EntityMetaService::class)->get($entity, $key, $default);
    }
}

if (! function_exists('meta_forget')) {
    function meta_forget(Model $entity, string $key): void
    {
        app(EntityMetaService::class)->forget($entity, $key);
    }
}

if (! function_exists('meta_all')) {
    /**
     * @return array<string, mixed>
     */
    function meta_all(Model $entity): array
    {
        return app(EntityMetaService::class)->all($entity);
    }
}

if (! function_exists('meta_preload')) {
    /**
     * Bulk preload meta for a collection — avoids N+1.
     *
     * @param  iterable<Model>  $entities
     * @param  array<string>|null  $keys
     * @return array<string, array<string, mixed>>
     */
    function meta_preload(iterable $entities, ?array $keys = null): array
    {
        return app(EntityMetaService::class)->bulkPreload($entities, $keys);
    }
}

// ─── Entity registry ───

if (! function_exists('entity_registry')) {
    /**
     * @return array<string, mixed>|null
     */
    function entity_registry(string $type): ?array
    {
        return EntityRegistry::get($type);
    }
}

if (! function_exists('entity_resolve')) {
    function entity_resolve(string $type, int $id): ?string
    {
        return EntityRegistry::call($type, 'resolve', [$id]);
    }
}

if (! function_exists('entity_search')) {
    /**
     * @return array<int, mixed>
     */
    function entity_search(string $type, string $term, int $limit = 20): array
    {
        return EntityRegistry::call($type, 'search', [$term, $limit]) ?? [];
    }
}

if (! function_exists('entity_search_all')) {
    /**
     * Search across all registered entity types.
     *
     * @return array<string, array<string, mixed>>
     */
    function entity_search_all(string $term, int $limitPerType = 5): array
    {
        $results = [];

        foreach (EntityRegistry::all() as $type => $config) {
            if (! isset($config['search']) || ! is_callable($config['search'])) {
                continue;
            }

            $items = EntityRegistry::call($type, 'search', [$term, $limitPerType]);

            if (! empty($items)) {
                $results[$type] = [
                    'label' => $config['label'] ?? $type,
                    'items' => $items,
                ];
            }
        }

        return $results;
    }
}

// ─── Notifications ───

if (! function_exists('notify_channels')) {
    /**
     * @return array<int, string>
     */
    function notify_channels(string $key): array
    {
        return NotificationRegistry::getChannels($key);
    }
}

// ─── Cache ───

if (! function_exists('cache_page_key')) {
    function cache_page_key(string $url): string
    {
        return 'page_cache_'.md5($url);
    }
}

if (! function_exists('flush_page_cache')) {
    function flush_page_cache(?string $url = null): void
    {
        app(CacheService::class)->flushPages($url);
    }
}

if (! function_exists('flush_fragment_cache')) {
    function flush_fragment_cache(?string $section = null): void
    {
        app(CacheService::class)->flushFragments($section);
    }
}
