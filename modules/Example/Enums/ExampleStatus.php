<?php

declare(strict_types=1);

namespace Modules\Example\Enums;

use App\Traits\EnumHelpers;

enum ExampleStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Published = 'published';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
