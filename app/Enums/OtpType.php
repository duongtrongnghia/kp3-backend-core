<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumHelpers;

enum OtpType: string
{
    use EnumHelpers;

    case REGISTRATION = 'registration';
    case TWO_FACTOR = '2fa';
    case VERIFICATION = 'verification';
    case PASSWORD_RESET = 'password_reset';

    /**
     * Determine if this OTP type should mark the contact as verified upon success.
     */
    public function shouldVerifyContact(): bool
    {
        return match ($this) {
            self::REGISTRATION,
            self::VERIFICATION,
            self::PASSWORD_RESET => true,
            self::TWO_FACTOR => false,
        };
    }
}
