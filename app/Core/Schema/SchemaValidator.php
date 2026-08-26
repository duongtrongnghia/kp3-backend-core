<?php

declare(strict_types=1);

namespace App\Core\Schema;

use DateTimeImmutable;

class SchemaValidator
{
    /**
     * Validate data array against field schema.
     *
     * @param  array<string, mixed>  $data  Input data to validate
     * @param  array<int, array<string, mixed>>  $fields  Field schema definitions
     * @param  string  $prefix  Key prefix for nested error paths
     * @return array<string, string> Field name → error message (empty = valid)
     */
    public static function validate(array $data, array $fields, string $prefix = ''): array
    {
        $errors = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            if ($name === '') {
                continue;
            }

            $key = $prefix !== '' ? "{$prefix}.{$name}" : $name;
            $value = $data[$name] ?? null;
            $label = $field['label'] ?? $name;
            $type = $field['type'] ?? 'text';

            // show_if: skip validation when condition not met
            if (isset($field['show_if']) && ! self::evaluateCondition($field['show_if'], $data)) {
                continue;
            }

            // Required check
            if (! empty($field['required']) && self::isEmpty($value)) {
                $errors[$key] = "{$label} is required.";

                continue;
            }

            // Skip further validation if empty and not required
            if (self::isEmpty($value)) {
                continue;
            }

            // Type-specific validation
            match ($type) {
                'number', 'range' => self::validateNumber($value, $field, $key, $label, $errors),
                'email' => self::validateEmail($value, $key, $label, $errors),
                'url', 'image' => self::validateUrl($value, $key, $label, $errors),
                'color' => self::validateColor($value, $key, $label, $errors),
                'select', 'radio' => self::validateOptions($value, $field, $key, $label, $errors),
                'checkbox' => self::validateCheckboxOptions($value, $field, $key, $label, $errors),
                'text', 'textarea' => self::validateText($value, $field, $key, $label, $errors),
                'date' => self::validateDate($value, $key, $label, $errors),
                'datetime' => self::validateDatetime($value, $key, $label, $errors),
                'group' => self::validateGroup($value, $field, $key, $errors),
                'repeater' => self::validateRepeater($value, $field, $key, $label, $errors),
                'toggle', 'richtext',
                'header', 'multi_select' => null, // no extra validation
                default => null,
            };
        }

        return $errors;
    }

    // ── Type validators ──────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $errors
     */
    private static function validateNumber(mixed $value, array $field, string $key, string $label, array &$errors): void
    {
        if (! is_numeric($value)) {
            $errors[$key] = "{$label} must be a number.";

            return;
        }

        $num = (float) $value;

        if (isset($field['min']) && $num < $field['min']) {
            $errors[$key] = "{$label} must be at least {$field['min']}.";
        } elseif (isset($field['max']) && $num > $field['max']) {
            $errors[$key] = "{$label} must be at most {$field['max']}.";
        }
    }

    /** @param array<string, string> $errors */
    private static function validateEmail(mixed $value, string $key, string $label, array &$errors): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $errors[$key] = "{$label} must be a valid email address.";
        }
    }

    /** @param array<string, string> $errors */
    private static function validateUrl(mixed $value, string $key, string $label, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[$key] = "{$label} must be a valid URL.";

            return;
        }

        // Allow relative paths starting with /
        if (str_starts_with($value, '/')) {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $errors[$key] = "{$label} must be a valid URL.";
        }
    }

    /** @param array<string, string> $errors */
    private static function validateColor(mixed $value, string $key, string $label, array &$errors): void
    {
        if (! is_string($value) || ! preg_match('/^#[0-9A-Fa-f]{3,8}$/', $value)) {
            $errors[$key] = "{$label} must be a valid hex color (e.g. #FF0000).";
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $errors
     */
    private static function validateOptions(mixed $value, array $field, string $key, string $label, array &$errors): void
    {
        $allowed = array_column($field['options'] ?? [], 'value');
        if (! in_array($value, $allowed, true)) {
            $errors[$key] = "{$label} has an invalid selection.";
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $errors
     */
    private static function validateCheckboxOptions(mixed $value, array $field, string $key, string $label, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[$key] = "{$label} must be an array.";

            return;
        }

        $allowed = array_column($field['options'] ?? [], 'value');
        foreach ($value as $v) {
            if (! in_array($v, $allowed, true)) {
                $errors[$key] = "{$label} contains an invalid option.";

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $errors
     */
    private static function validateText(mixed $value, array $field, string $key, string $label, array &$errors): void
    {
        if (! is_string($value)) {
            return;
        }

        $len = mb_strlen($value);

        if (isset($field['min']) && $len < $field['min']) {
            $errors[$key] = "{$label} must be at least {$field['min']} characters.";
        } elseif (isset($field['max']) && $len > $field['max']) {
            $errors[$key] = "{$label} must be at most {$field['max']} characters.";
        }

        if (isset($field['pattern']) && ! preg_match('/'.$field['pattern'].'/', $value)) {
            $errors[$key] = "{$label} format is invalid.";
        }
    }

    /** @param array<string, string> $errors */
    private static function validateDate(mixed $value, string $key, string $label, array &$errors): void
    {
        if (! is_string($value) || strtotime($value) === false) {
            $errors[$key] = "{$label} must be a valid date.";
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private static function validateDatetime(mixed $value, string $key, string $label, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[$key] = "{$label} must be a valid date/time.";

            return;
        }

        // createFromFormat() returns DateTimeImmutable|false — chain with explicit false checks
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if ($dt === false) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        }
        if ($dt === false) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $value);
        }

        if ($dt === false && strtotime($value) === false) {
            $errors[$key] = "{$label} must be a valid date/time.";
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $errors
     */
    private static function validateGroup(mixed $value, array $field, string $key, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[$key] = "{$field['label']} must be an object.";

            return;
        }

        $subErrors = self::validate($value, $field['fields'] ?? [], $key);
        foreach ($subErrors as $k => $v) {
            $errors[$k] = $v;
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $errors
     */
    private static function validateRepeater(mixed $value, array $field, string $key, string $label, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[$key] = "{$label} must be a list.";

            return;
        }

        $count = count($value);

        if (isset($field['min']) && $count < $field['min']) {
            $errors[$key] = "{$label} must have at least {$field['min']} items.";

            return;
        }

        if (isset($field['max']) && $count > $field['max']) {
            $errors[$key] = "{$label} must have at most {$field['max']} items.";

            return;
        }

        $subFields = $field['fields'] ?? [];
        foreach ($value as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $subErrors = self::validate($item, $subFields, "{$key}.{$i}");
            foreach ($subErrors as $k => $v) {
                $errors[$k] = $v;
            }
        }
    }

    // ── Condition evaluation ─────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $data
     */
    private static function evaluateCondition(array $condition, array $data): bool
    {
        $field = $condition['field'] ?? '';
        $op = $condition['op'] ?? 'eq';
        $target = $condition['value'] ?? null;
        $current = $data[$field] ?? null;

        return match ($op) {
            'eq' => $current === $target,
            'neq' => $current !== $target,
            'gt' => is_numeric($current) && is_numeric($target) && $current > $target,
            'lt' => is_numeric($current) && is_numeric($target) && $current < $target,
            'in' => is_array($target) && in_array($current, $target, true),
            'not_empty' => ! self::isEmpty($current),
            'truthy' => (bool) $current,
            'falsy' => ! (bool) $current,
            default => true,
        };
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
