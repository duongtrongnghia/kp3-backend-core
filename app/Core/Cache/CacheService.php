<?php

declare(strict_types=1);

namespace App\Core\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Centralized cache orchestrator — manages all cache layers with tag-based invalidation.
 *
 * Replaces scattered Cache::forget/Cache::flush calls with layer-aware operations.
 * Uses custom TagRegistry (not Laravel Cache::tags) to avoid Redis memory leaks.
 */
class CacheService
{
    // Layer prefixes for layer-scoped flush
    public const LAYER_PAGE = 'page_cache_';

    public const LAYER_FRAGMENT = 'fragment_';

    public const LAYER_SETTING = 'setting_';

    public const LAYER_MODULE = 'module_setting:';

    // Sentinel to distinguish "not cached" from "cached as null"
    private const NULL_SENTINEL = '__CACHE_NULL__';

    public function __construct(
        protected TagRegistry $tagRegistry,
    ) {}

    // ─── Core CRUD ───

    /**
     * Store a value in cache with optional tags.
     */
    /**
     * @param  string[]  $tags
     */
    public function put(string $key, mixed $value, ?int $ttl = null, array $tags = []): void
    {
        $ttl = $ttl ?? config('core.cache.page_ttl', 3600);
        Cache::put($key, $value ?? self::NULL_SENTINEL, $ttl);

        if (! empty($tags)) {
            $this->tagRegistry->tag($key, $tags);
        }
    }

    /**
     * Retrieve a value from cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::get($key, $default);

        return $value === self::NULL_SENTINEL ? null : $value;
    }

    /**
     * Check if a key exists in cache.
     */
    public function has(string $key): bool
    {
        return Cache::has($key);
    }

    /**
     * Cache a value using remember pattern (get or compute+store).
     */
    /**
     * @param  string[]  $tags
     */
    public function remember(string $key, int $ttl, Closure $callback, array $tags = []): mixed
    {
        $cached = Cache::get($key);

        if ($cached === self::NULL_SENTINEL) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->put($key, $value ?? self::NULL_SENTINEL, $ttl, $tags);

        return $value;
    }

    /**
     * Remove a cached item and its tag associations.
     */
    public function forget(string $key): void
    {
        $this->tagRegistry->untag($key);
        Cache::forget($key);
    }

    // ─── Tag-based Invalidation ───

    /**
     * Invalidate all cached items associated with a tag.
     */
    public function invalidateTag(string $tag): void
    {
        $keys = $this->tagRegistry->getKeysByTag($tag);

        foreach ($keys as $key) {
            $this->forget($key);
        }
    }

    /**
     * Shortcut: invalidate by entity type + ID.
     * Flushes tag "{type}:{id}" (e.g. "post:123").
     */
    public function invalidateEntity(string $type, int|string $id): void
    {
        $this->invalidateTag("{$type}:{$id}");
    }

    // ─── Layer-scoped Flush ───

    /**
     * Flush page cache — specific URL or all pages.
     * Does NOT touch settings, permissions, or fragment cache.
     */
    public function flushPages(?string $url = null): void
    {
        if ($url !== null) {
            $key = self::LAYER_PAGE.md5($url);
            $this->forget($key);

            return;
        }

        // Flush all pages via tag
        $this->invalidateTag('pages');

        // Safety: also scan for any untagged page cache keys
        $this->flushByPrefix(self::LAYER_PAGE);
    }

    /**
     * Flush fragment cache — specific section or all fragments.
     * Does NOT touch page cache, settings, or permissions.
     */
    public function flushFragments(?string $section = null): void
    {
        if ($section !== null) {
            $key = self::LAYER_FRAGMENT.md5($section);
            $this->forget($key);

            return;
        }

        // Flush all fragments via tag
        $this->invalidateTag('fragments');

        // Safety: also scan for any untagged fragment cache keys
        $this->flushByPrefix(self::LAYER_FRAGMENT);
    }

    /**
     * Nuclear option — flush entire cache store. Use sparingly (deploy, theme switch).
     */
    public function flushAll(): void
    {
        Cache::flush();
    }

    // ─── Debug & Status ───

    /**
     * Get cache status for debugging.
     */
    /**
     * @return array{driver: mixed, prefix: string, layers: array{pages: int, fragments: int, other: int}, tags: array<string, mixed>}
     */
    public function status(): array
    {
        $tagStats = $this->tagRegistry->stats();

        // Count keys by layer prefix via Redis scan
        $prefix = config('cache.prefix', 'laravel_cache').':';
        $layers = [
            'pages' => 0,
            'fragments' => 0,
            'other' => 0,
        ];

        try {
            foreach ($this->scanRedisKeys($prefix.'*') as $key) {
                $stripped = str_replace($prefix, '', $key);
                if (str_starts_with($stripped, self::LAYER_PAGE)) {
                    $layers['pages']++;
                } elseif (str_starts_with($stripped, self::LAYER_FRAGMENT)) {
                    $layers['fragments']++;
                } elseif (! str_starts_with($stripped, 'cache_tag:') && ! str_starts_with($stripped, 'cache_key_tags:')) {
                    $layers['other']++;
                }
            }
        } catch (Throwable) {
            // Redis unavailable — return what we can
        }

        return [
            'driver' => config('cache.default'),
            'prefix' => $prefix,
            'layers' => $layers,
            'tags' => $tagStats,
        ];
    }

    // ─── Internal ───

    /**
     * Flush all cache keys matching a prefix (layer-scoped).
     */
    protected function flushByPrefix(string $layerPrefix): void
    {
        $prefix = config('cache.prefix', 'laravel_cache').':';

        try {
            $redis = Redis::connection(config('database.redis.cache.connection', 'cache'));
            $keys = $this->scanRedisKeys($prefix.$layerPrefix.'*');

            if (! empty($keys)) {
                $redis->del(...$keys);

                foreach ($keys as $fullKey) {
                    $cacheKey = str_replace($prefix, '', $fullKey);
                    $this->tagRegistry->untag($cacheKey);
                }
            }
        } catch (Throwable) {
            // Fallback: if Redis scan fails, items will expire via TTL
        }
    }

    /**
     * Scan Redis keys matching a pattern. Handles Predis cursor API.
     *
     * SCAN does not auto-prefix like other Redis commands, so we prepend
     * the connection prefix manually and strip it from results.
     */
    /**
     * Scan Redis keys matching a pattern. Handles Predis cursor API.
     *
     * SCAN does not auto-prefix like other Redis commands, so we prepend
     * the connection prefix manually and strip it from results.
     *
     * @return string[]
     */
    protected function scanRedisKeys(string $pattern): array
    {
        $redis = Redis::connection(config('database.redis.cache.connection', 'cache'));
        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $allKeys = [];
        $cursor = '0';
        $fullPattern = $redisPrefix.$pattern;

        do {
            // scan() returns [cursor, keys] — cursor may be string '0' when done
            $result = $redis->scan($cursor, $fullPattern, 100);
            if ($result === false) {
                break;
            }
            $cursor = (string) $result[0];
            /** @var mixed $rawKeys */
            $rawKeys = $result[1];
            if (! is_array($rawKeys) || empty($rawKeys)) {
                continue;
            }
            foreach ($rawKeys as $key) {
                $allKeys[] = substr((string) $key, strlen($redisPrefix));
            }
        } while ($cursor !== '0');

        return $allKeys;
    }
}
