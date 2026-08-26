<?php

declare(strict_types=1);

namespace App\Traits;

trait EnumHelpers
{
    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /** @return array<string, string> name => value */
    public static function array(): array
    {
        return array_combine(self::names(), self::values());
    }

    public function label(): string
    {
        $className = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($this)));

        return __("api.{$className}.{$this->value}");
    }
}
