<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumHelpers;

enum UserStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Inactive = 'inactive';
    case Locked = 'locked';
    // Exposed for FE type-guard + StatusCell uniformity.
    case PendingInvite = 'pending_invite';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('users.status.active'),
            self::Inactive => __('users.status.inactive'),
            self::Locked => __('users.status.locked'),
            self::PendingInvite => __('users.status.pending_invite'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'warning',
            self::Locked => 'error',
            self::PendingInvite => 'purple',
        };
    }
}
