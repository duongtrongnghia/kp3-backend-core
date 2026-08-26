<?php

declare(strict_types=1);

namespace Modules\Example;

/**
 * Single source of truth for this module's hook names.
 *
 * Always reference these constants instead of raw strings so a typo becomes a
 * compile-time error (enforced by App\Tools\PHPStan\HookConstantRule).
 */
final class HookConstants
{
    public const CREATED = 'example.created';

    public const UPDATED = 'example.updated';

    public const BEFORE_DELETE = 'example.before_delete';
}
