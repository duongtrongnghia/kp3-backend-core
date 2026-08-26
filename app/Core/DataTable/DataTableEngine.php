<?php

declare(strict_types=1);

namespace App\Core\DataTable;

use App\Core\Providers\ModuleServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Auto-generate Eloquent queries from DataTableRegistry metadata.
 *
 * Usage:
 *   $query = DataTableEngine::query(InventoryItem::class)
 *       ->throughMorph('stockable', 'product')
 *       ->withExtensions('inventory.list')
 *       ->applyFilters($request)
 *       ->applySearch($request->input('search'))
 *       ->applyEagerLoads()
 *       ->getQuery();
 */
class DataTableEngine
{
    /** @var Builder<Model> */
    private Builder $query;

    private ?string $morphRelation = null;

    private ?string $morphTargetClass = null;

    /** @var array<string, array<string, mixed>> Resolved filter metadata */
    private array $filters = [];

    /** @var array<string, array<string, mixed>> Resolved search scope metadata */
    private array $searchScopes = [];

    /** @var array<string, array<string, mixed>> Resolved column metadata */
    private array $columns = [];

    private function __construct(string $modelClass)
    {
        $this->query = $modelClass::query();
    }

    /**
     * Start building a query for the given model.
     */
    public static function query(string $modelClass): self
    {
        return new self($modelClass);
    }

    /**
     * Declare that cross-module filters go through a morph relation.
     *
     * Example: InventoryItem queries Product through morphTo('stockable').
     *   ->throughMorph('stockable', 'product')
     *
     * @param  string  $morphRelation  The morphTo relation name on the base model
     * @param  string  $morphType  The morph type alias (from morphMap)
     */
    public function throughMorph(string $morphRelation, string $morphType): static
    {
        $this->morphRelation = $morphRelation;
        $this->morphTargetClass = Relation::getMorphedModel($morphType);

        return $this;
    }

    /**
     * Apply registered filters based on request parameters.
     *
     * For each registered filter, checks if the request contains the param.
     * Auto-generates query based on filter type:
     *   - relation: whereHasOptional($relation, whereIn('id', $values))
     *   - enum: where($column, $value)
     *   - boolean: where($column, (bool) $value)
     *   - custom: call the provided query callable
     *
     * If throughMorph is set, wraps relation filters in whereHasMorph.
     */
    public function applyFilters(Request $request): static
    {
        foreach ($this->filters as $paramName => $metadata) {
            $value = $request->input($paramName);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $type = $metadata['type'] ?? 'relation';

            if ($type === 'custom' && isset($metadata['query']) && \is_callable($metadata['query'])) {
                ($metadata['query'])($this->query, $value);

                continue;
            }

            if ($type === 'relation') {
                $this->applyRelationFilter($metadata, $value);

                continue;
            }

            if ($type === 'enum' || $type === 'boolean') {
                $column = $metadata['column'] ?? $paramName;
                $this->query->where($column, $type === 'boolean' ? (bool) $value : $value);
            }
        }

        return $this;
    }

    /**
     * Apply search across base model fields and registered search scopes.
     *
     * Base search (e.g. SKU) is NOT handled here — keep in your service.
     * This method handles cross-module search scopes only.
     */
    public function applySearch(?string $term): static
    {
        if (empty($term) || empty($this->searchScopes)) {
            return $this;
        }

        $this->query->where(function (Builder $q) use ($term) {
            foreach ($this->searchScopes as $metadata) {
                $fields = $metadata['fields'] ?? [];
                if (empty($fields)) {
                    continue;
                }

                if ($this->morphRelation && $this->morphTargetClass) {
                    // Search through morph relation
                    $targetClass = $this->morphTargetClass;

                    $q->orWhereHasMorph($this->morphRelation, [$targetClass], function (Builder $mq) use ($fields, $term) {
                        $mq->where(function (Builder $inner) use ($fields, $term) {
                            foreach ($fields as $field) {
                                $inner->orWhere($field, 'like', "%{$term}%");
                            }
                        });
                    });
                } else {
                    // Direct search on model
                    foreach ($fields as $field) {
                        $q->orWhere($field, 'like', "%{$term}%");
                    }
                }
            }
        });

        return $this;
    }

    /**
     * Auto eager-load relations needed for column rendering.
     */
    public function applyEagerLoads(): static
    {
        $relations = [];
        foreach ($this->columns as $metadata) {
            if (! empty($metadata['relation'])) {
                $baseName = explode('.', $metadata['relation'])[0];
                $relations[] = $baseName;
            }
        }

        if (! empty($relations)) {
            $this->query->with(array_unique($relations));
        }

        return $this;
    }

    /**
     * Get the underlying Eloquent Builder.
     *
     * @return Builder<Model>
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Apply a relation-type filter, handling morph chains automatically.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function applyRelationFilter(array $metadata, mixed $value): void
    {
        $relation = $metadata['relation'] ?? null;
        if (! $relation) {
            return;
        }

        $values = \is_array($value) ? $value : [$value];
        // Qualify table name to avoid ambiguous 'id' in nested subqueries
        $filterCallback = fn (Builder $q) => $q->whereIn($q->getModel()->getTable().'.id', $values);

        if ($this->morphRelation && $this->morphTargetClass) {
            // Check if the target model has this relation
            $injectedRelations = ModuleServiceProvider::getInjectedRelations($this->morphTargetClass);
            $targetInstance = new $this->morphTargetClass;
            // isRelation() is defined on Eloquent Model; method_exists guards the call for PHPStan.
            $hasRelation = \in_array($relation, $injectedRelations, true)
                || (method_exists($targetInstance, 'isRelation') && $targetInstance->isRelation($relation));

            if (! $hasRelation) {
                return; // Relation doesn't exist on target — skip silently
            }

            // Wrap in whereHasMorph
            $targetClass = $this->morphTargetClass;
            $this->query->whereHasMorph($this->morphRelation, [$targetClass], function (Builder $mq) use ($relation, $filterCallback) {
                $mq->whereHas($relation, $filterCallback);
            });
        } else {
            // Direct relation on the base model
            $this->query->whereHasOptional($relation, $filterCallback);
        }
    }
}
