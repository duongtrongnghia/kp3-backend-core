<?php

declare(strict_types=1);

namespace Modules\Example\Services;

use App\Traits\Transactional;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Example\HookConstants;
use Modules\Example\Models\Example;

/**
 * Example business logic.
 *
 * All write paths are transactional and fire hooks so other modules can react
 * without coupling. Controllers stay thin — no business logic lives there.
 */
class ExampleService
{
    use Transactional;

    /**
     * Paginated, filtered list.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Example>
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Example::query()->with('author');

        if (! empty($filters['status'])) {
            $query->status((string) $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']);
            $query->where('title', 'like', "%{$term}%");
        }

        if (array_key_exists('is_featured', $filters) && $filters['is_featured'] !== null) {
            $query->where('is_featured', (bool) $filters['is_featured']);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Example
    {
        return $this->transactional(function () use ($data): Example {
            $data['slug'] = $this->uniqueSlug((string) ($data['slug'] ?? $data['title']));

            $example = Example::create($data);

            // Optional meta payload (demonstrates the universal meta API).
            if (! empty($data['meta']) && is_array($data['meta'])) {
                foreach ($data['meta'] as $key => $value) {
                    $example->setMeta((string) $key, $value);
                }
            }

            do_action(HookConstants::CREATED, $example);

            return $example;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Example $example, array $data): Example
    {
        return $this->transactional(function () use ($example, $data): Example {
            if (! empty($data['slug']) && $data['slug'] !== $example->slug) {
                $data['slug'] = $this->uniqueSlug((string) $data['slug'], $example->id);
            }

            $example->update($data);

            do_action(HookConstants::UPDATED, $example);

            return $example->refresh();
        });
    }

    public function delete(Example $example): void
    {
        $this->transactional(function () use ($example): void {
            do_action(HookConstants::BEFORE_DELETE, $example);
            $example->delete();
        });
    }

    /**
     * Generate a slug unique across the table (ignoring $ignoreId on update).
     */
    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'example';
        $slug = $base;
        $i = 2;

        while (
            Example::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
