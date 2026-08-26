<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumHelpers;

enum TwoFactorType: string
{
    use EnumHelpers;

    case EMAIL = 'email';
    case PHONE = 'phone';
    case APP = 'app';

    public function isApp(): bool
    {
        return $this === self::APP;
    }

    public function requiresOtp(): bool
    {
        return in_array($this, [self::EMAIL, self::PHONE], true);
    }
}
