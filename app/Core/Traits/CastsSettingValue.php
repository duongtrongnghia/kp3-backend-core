<?php

declare(strict_types=1);

namespace App\Core\Traits;

use InvalidArgumentException;

/**
 * Shared value casting logic for key-value models (ModuleSetting, Meta).
 * Handles type-safe encode/decode with json_encode error handling.
 */
trait CastsSettingValue
{
    /**
     * Cast stored string value based on type column.
     */
    public function getCastedValueAttribute(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => in_array($this->value, ['1', 'true', 'yes', 'on'], true),
            'json', 'array' => json_decode((string) $this->value, true) ?? [],
            default => $this->value,
        };
    }

    /**
     * Encode value for database storage.
     *
     * @throws InvalidArgumentException If JSON encoding fails
     */
    public static function encodeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'json', 'array' => self::encodeJson($value),
            'boolean' => self::castBool($value) ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * Convert a mixed truthy/string boolean to a genuine bool.
     * Treats 'false', '0', '', null, false as falsy — everything else as truthy.
     */
    protected static function castBool(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== 'false' && $value !== '0' && $value !== '';
        }

        return (bool) $value;
    }

    protected static function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new InvalidArgumentException('Failed to encode value as JSON: '.json_last_error_msg());
        }

        return $encoded;
    }
}
