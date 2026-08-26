<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Core\Models\Meta;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasMeta
{
    /**
     * @return MorphMany<Meta, $this>
     */
    public function meta(): MorphMany
    {
        return $this->morphMany(Meta::class, 'metaable');
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        // Auto-detect eager-loaded relation to avoid N+1
        if ($this->relationLoaded('meta')) {
            $meta = $this->meta->firstWhere('key', $key);

            return $meta ? $meta->casted_value : $default;
        }

        $meta = $this->meta()->where('key', $key)->first();

        return $meta ? $meta->casted_value : $default;
    }

    public function setMeta(string $key, mixed $value, string $type = 'string'): void
    {
        $this->meta()->updateOrCreate(
            ['key' => $key],
            ['value' => Meta::encodeValue($value, $type), 'type' => $type]
        );
    }

    public function deleteMeta(string $key): void
    {
        $this->meta()->where('key', $key)->delete();
    }

    /**
     * @return Collection<string, mixed>
     */
    public function getAllMeta(): Collection
    {
        return $this->meta->mapWithKeys(fn (Meta $m) => [$m->key => $m->casted_value]);
    }

    /**
     * Eager load: Post::with('meta')->get()
     * Then: $post->getMetaFromLoaded('seo_title')
     * Avoids N+1 when accessing meta in loops.
     */
    public function getMetaFromLoaded(string $key, mixed $default = null): mixed
    {
        if (! $this->relationLoaded('meta')) {
            return $this->getMeta($key, $default);
        }

        $meta = $this->meta->firstWhere('key', $key);

        return $meta ? $meta->casted_value : $default;
    }

    /**
     * Boot: cascade delete meta when model deleted.
     */
    public static function bootHasMeta(): void
    {
        static::deleting(function ($model) {
            $model->meta()->delete();
        });
    }
}
