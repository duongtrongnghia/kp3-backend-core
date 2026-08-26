<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumHelpers;

enum Gender: string
{
    use EnumHelpers;

    case MALE = 'male';
    case FEMALE = 'female';
    case OTHER = 'other';
}
