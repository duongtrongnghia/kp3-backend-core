<?php

declare(strict_types=1);

namespace Modules\Example\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Example\Models\Example;

/**
 * @mixin Example
 */
class ExampleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => $this->body,
            'status' => $this->status->value,
            'is_featured' => $this->is_featured,
            'author' => $this->whenLoaded('author', function (): ?array {
                $author = $this->author;

                return $author instanceof User ? [
                    'id' => $author->id,
                    'name' => trim(($author->first_name ?? '').' '.($author->last_name ?? '')),
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
