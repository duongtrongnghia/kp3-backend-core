<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumHelpers;

enum UserRole: string
{
    use EnumHelpers;

    case ADMIN = 'admin';
    case CUSTOMER = 'customer';

    public function isAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    public function isCustomer(): bool
    {
        return $this === self::CUSTOMER;
    }

    /**
     * Numeric privilege level used for 3-gate authorization in AdminUserService.
     * Higher value = more privilege. ADMIN > CUSTOMER.
     */
    public function level(): int
    {
        return match ($this) {
            self::ADMIN => 100,
            self::CUSTOMER => 10,
        };
    }
}
