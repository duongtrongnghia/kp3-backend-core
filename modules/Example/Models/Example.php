<?php

declare(strict_types=1);

namespace Modules\Example\Models;

use App\Core\Traits\HasMeta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Example\Database\Factories\ExampleFactory;
use Modules\Example\Enums\ExampleStatus;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string $slug
 * @property string|null $body
 * @property ExampleStatus $status
 * @property bool $is_featured
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Example extends Model
{
    /** @use HasFactory<ExampleFactory> */
    use HasFactory;

    use HasMeta;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'body',
        'status',
        'is_featured',
    ];

    /**
     * In-memory defaults (mirror the DB column defaults) so a freshly created
     * model exposes a valid status/flag before reloading from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_featured' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExampleStatus::class,
            'is_featured' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Filter by status.
     *
     * @param  Builder<Example>  $query
     * @return Builder<Example>
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @return Factory<Example>
     */
    protected static function newFactory(): Factory
    {
        return ExampleFactory::new();
    }
}
