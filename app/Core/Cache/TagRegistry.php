<?php

declare(strict_types=1);

namespace App\Core\Cache;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Custom tag registry using Redis SETs (NOT Laravel Cache::tags which leaks memory).
 *
 * Each tag maps to a Redis SET containing cache keys.
 * Each cache key maps to a reverse-index SET containing its tags.
 * Both SETs have TTL to prevent unbounded memory growth.
 */
class TagRegistry
{
    protected string $prefix;

    protected string $redisPrefix;

    protected int $maxTtl;

    public function __construct()
    {
        $this->prefix = config('cache.prefix', 'laravel_cache').':';
        $this->redisPrefix = config('database.redis.options.prefix', '');
        $this->maxTtl = config('core.cache.tag_max_ttl', 86400);
    }

    /**
     * Associate a cache key with one or more tags.
     *
     * @param  string[]  $tags
     */
    public function tag(string $cacheKey, array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        try {
            $redis = $this->redis();

            // Forward index: tag → SET of cache keys
            foreach ($tags as $tag) {
                $tagSetKey = $this->tagSetKey($tag);
                $redis->sadd($tagSetKey, $cacheKey);
                $redis->expire($tagSetKey, $this->maxTtl);
            }

            // Reverse index: cache key → SET of tags (for untag cleanup)
            $reverseKey = $this->reverseKey($cacheKey);
            $redis->sadd($reverseKey, ...$tags);
            $redis->expire($reverseKey, $this->maxTtl);
        } catch (Throwable) {
            // Redis unavailable — tagging is best-effort
        }
    }

    /**
     * Remove a cache key from all its associated tags.
     */
    public function untag(string $cacheKey): void
    {
        try {
            $redis = $this->redis();
            $reverseKey = $this->reverseKey($cacheKey);

            $tags = $redis->smembers($reverseKey);
            if (! empty($tags)) {
                foreach ($tags as $tag) {
                    $redis->srem($this->tagSetKey($tag), $cacheKey);
                }
            }

            $redis->del($reverseKey);
        } catch (Throwable) {
            // Redis unavailable — untagging is best-effort
        }
    }

    /**
     * Get all cache keys associated with a tag.
     *
     * @return string[]
     */
    public function getKeysByTag(string $tag): array
    {
        try {
            $result = $this->redis()->smembers($this->tagSetKey($tag));

            return is_array($result) ? $result : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get all tags for a cache key.
     *
     * @return string[]
     */
    public function getTagsForKey(string $cacheKey): array
    {
        try {
            $result = $this->redis()->smembers($this->reverseKey($cacheKey));

            return is_array($result) ? $result : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Remove stale references — cache keys that no longer exist in the cache store.
     */
    public function cleanup(): int
    {
        try {
            $redis = $this->redis();
            $cleaned = 0;
            $pattern = $this->prefix.'cache_tag:*';

            foreach ($this->scanKeys($pattern) as $tagSetKey) {
                $members = $redis->smembers($tagSetKey);
                foreach ($members as $cacheKey) {
                    $actualRedisKey = $this->prefix.$cacheKey;
                    if (! $redis->exists($actualRedisKey)) {
                        $redis->srem($tagSetKey, $cacheKey);
                        $cleaned++;
                    }
                }
            }

            return $cleaned;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Get stats: number of tags, total references.
     *
     * @return array{tag_count: int, total_refs: int, tags: array<string, mixed>}
     */
    public function stats(): array
    {
        try {
            $redis = $this->redis();
            $pattern = $this->prefix.'cache_tag:*';
            $tags = [];
            $totalRefs = 0;

            foreach ($this->scanKeys($pattern) as $tagSetKey) {
                $tag = str_replace($this->prefix.'cache_tag:', '', $tagSetKey);
                $count = $redis->scard($tagSetKey);
                $tags[$tag] = $count;
                $totalRefs += $count;
            }

            return [
                'tag_count' => count($tags),
                'total_refs' => $totalRefs,
                'tags' => $tags,
            ];
        } catch (Throwable) {
            return ['tag_count' => 0, 'total_refs' => 0, 'tags' => []];
        }
    }

    /**
     * Scan Redis keys matching a pattern. Handles Predis cursor API.
     *
     * SCAN does not auto-prefix like other Redis commands, so we prepend
     * the connection prefix manually and strip it from results.
     *
     * @return string[]
     */
    protected function scanKeys(string $pattern): array
    {
        $redis = $this->redis();
        $allKeys = [];
        $cursor = '0';
        $fullPattern = $this->redisPrefix.$pattern;

        do {
            // scan() returns [cursor, keys] — cursor is string '0' when exhausted
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
            // Strip Redis connection prefix so keys work with auto-prefixed commands
            foreach ($rawKeys as $key) {
                $allKeys[] = substr((string) $key, strlen($this->redisPrefix));
            }
        } while ($cursor !== '0');

        return $allKeys;
    }

    /**
     * Redis SET key for a tag's forward index.
     */
    protected function tagSetKey(string $tag): string
    {
        return $this->prefix.'cache_tag:'.$tag;
    }

    /**
     * Redis SET key for a cache key's reverse index.
     */
    protected function reverseKey(string $cacheKey): string
    {
        return $this->prefix.'cache_key_tags:'.$cacheKey;
    }

    protected function redis(): Connection
    {
        return Redis::connection(config('database.redis.cache.connection', 'cache'));
    }
}
