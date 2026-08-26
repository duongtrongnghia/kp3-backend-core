<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Traits\CastsSettingValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property-read mixed $casted_value
 */
class Meta extends Model
{
    use CastsSettingValue;

    protected $table = 'metaables';

    protected $fillable = ['metaable_type', 'metaable_id', 'key', 'value', 'type'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function metaable(): MorphTo
    {
        return $this->morphTo();
    }
}
