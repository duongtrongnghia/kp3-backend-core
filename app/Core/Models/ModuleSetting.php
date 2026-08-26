<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Traits\CastsSettingValue;
use Illuminate\Database\Eloquent\Model;

class ModuleSetting extends Model
{
    use CastsSettingValue;

    protected $table = 'module_settings';

    protected $fillable = ['module', 'key', 'value', 'type'];
}
