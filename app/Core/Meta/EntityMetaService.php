<?php

declare(strict_types=1);

namespace App\Core\Meta;

use App\Core\Models\Meta;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Universal entity meta service — works for ANY Eloquent model.
 *
 * Primary API for plugins: use global helpers meta_set(), meta_get(), meta_forget(),
 * meta_all(), meta_preload(). Any model with an `id` and `getMorphClass()` can store meta
 * without needing the HasMeta trait.
 *
 * Storage: polymorphic `metaables` table, composite index (metaable_type, metaable_id).
 *
 * Guard rails:
 *   - G2: 64KB per value cap (tránh oversized payload bloat)
 *   - G3: bulkPreload() to avoid N+1 across collections
 *   - G5: plugin naming convention `{plugin_prefix}_{field}` (documented)
 */
class EntityMetaService
{
    public const MAX_VALUE_BYTES = 64 * 1024;

    public function set(Model $entity, string $key, mixed $value, string $type = 'string'): void
    {
        $encoded = Meta::encodeValue($value, $type);

        if ($encoded !== null && strlen($encoded) > self::MAX_VALUE_BYTES) {
            throw new InvalidArgumentException(
                sprintf('Meta value for "%s" exceeds %dKB limit (size: %d bytes)', $key, self::MAX_VALUE_BYTES / 1024, strlen($encoded))
            );
        }

        // Atomic upsert (race-safe): relies on unique index (metaable_type, metaable_id, key).
        // updateOrCreate does SELECT-then-INSERT which can produce unique-violation on concurrent
        // writers. upsert() uses ON CONFLICT/ON DUPLICATE KEY UPDATE natively.
        $now = now();
        Meta::query()->upsert(
            [[
                'metaable_type' => $entity->getMorphClass(),
                'metaable_id' => $entity->getKey(),
                'key' => $key,
                'value' => $encoded,
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['metaable_type', 'metaable_id', 'key'],
            ['value', 'type', 'updated_at']
        );
    }

    public function get(Model $entity, string $key, mixed $default = null): mixed
    {
        // Honor eager-loaded relation when present (avoid N+1)
        if ($entity->relationLoaded('meta')) {
            /** @var EloquentCollection<int, Meta> $metaCollection */
            $metaCollection = $entity->getRelation('meta');
            $hit = $metaCollection->firstWhere('key', $key);

            return $hit ? $hit->casted_value : $default;
        }

        $meta = Meta::query()
            ->where('metaable_type', $entity->getMorphClass())
            ->where('metaable_id', $entity->getKey())
            ->where('key', $key)
            ->first();

        return $meta ? $meta->casted_value : $default;
    }

    public function forget(Model $entity, string $key): void
    {
        Meta::query()
            ->where('metaable_type', $entity->getMorphClass())
            ->where('metaable_id', $entity->getKey())
            ->where('key', $key)
            ->delete();
    }

    /**
     * All meta for a single entity as [key => casted_value] array.
     *
     * @return array<string, mixed>
     */
    public function all(Model $entity): array
    {
        return Meta::query()
            ->where('metaable_type', $entity->getMorphClass())
            ->where('metaable_id', $entity->getKey())
            ->get()
            ->mapWithKeys(fn (Meta $m) => [$m->key => $m->casted_value])
            ->all();
    }

    /**
     * Bulk preload meta for a collection — single query instead of N.
     * Returns array keyed by `{morph_class}:{id}` with value being [key => casted_value] map.
     *
     * Usage:
     *   $orders = Order::paginate(20);
     *   $metas  = meta_preload($orders);
     *   foreach ($orders as $o) {
     *       $invoice = $metas[$o->getMorphClass().':'.$o->id]['misa_invoice_no'] ?? null;
     *   }
     *
     * @param  EloquentCollection<int, Model>|Collection<int, mixed>|iterable<int, mixed>  $entities
     * @param  array<string>|null  $keys  Optional allowlist of meta keys to load
     * @return array<string, array<string, mixed>>
     */
    public function bulkPreload(iterable $entities, ?array $keys = null): array
    {
        $grouped = [];
        foreach ($entities as $entity) {
            if (! $entity instanceof Model) {
                continue;
            }
            $type = $entity->getMorphClass();
            $grouped[$type][] = $entity->getKey();
        }

        if (empty($grouped)) {
            return [];
        }

        $query = Meta::query();
        $query->where(function ($outer) use ($grouped) {
            foreach ($grouped as $type => $ids) {
                $outer->orWhere(function ($inner) use ($type, $ids) {
                    $inner->where('metaable_type', $type)->whereIn('metaable_id', $ids);
                });
            }
        });
        if ($keys) {
            $query->whereIn('key', $keys);
        }

        $out = [];
        foreach ($query->get() as $m) {
            $out[$m->metaable_type.':'.$m->metaable_id][$m->key] = $m->casted_value;
        }

        return $out;
    }
}
