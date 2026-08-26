<?php

declare(strict_types=1);

namespace App\Core\Schema;

class SchemaHelper
{
    /**
     * Extract default values from field schema.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function defaults(array $fields): array
    {
        $defaults = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            if ($name === '') {
                continue;
            }

            $type = $field['type'] ?? 'text';

            if ($type === 'group' && isset($field['fields'])) {
                $defaults[$name] = self::defaults($field['fields']);
            } elseif ($type === 'repeater') {
                $defaults[$name] = [];
            } elseif (array_key_exists('default', $field)) {
                $defaults[$name] = $field['default'];
            }
        }

        return $defaults;
    }

    /**
     * Sanitize data based on field type definitions.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function sanitize(array $data, array $fields): array
    {
        $clean = [];
        $fieldMap = self::indexByName($fields);

        foreach ($data as $name => $value) {
            if (! isset($fieldMap[$name])) {
                continue; // strip unknown fields
            }

            $field = $fieldMap[$name];
            $type = $field['type'] ?? 'text';

            $clean[$name] = match ($type) {
                'text', 'textarea' => is_string($value) ? strip_tags(trim($value)) : (string) $value,
                'richtext' => is_string($value) ? self::sanitizeHtml($value) : '',
                'number', 'range' => is_numeric($value) ? (float) $value : null,
                'toggle' => (bool) $value,
                'url', 'image' => is_string($value) ? filter_var(trim($value), FILTER_SANITIZE_URL) : '',
                'email' => is_string($value) ? filter_var(trim($value), FILTER_SANITIZE_EMAIL) : '',
                'color' => is_string($value) && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $value) ? $value : null,
                'select', 'radio' => is_string($value) ? $value : '',
                'checkbox', 'multi_select' => is_array($value) ? array_values(array_filter($value, 'is_string')) : [],
                'date', 'datetime' => is_string($value) ? trim($value) : '',
                'group' => is_array($value) ? self::sanitize($value, $field['fields'] ?? []) : [],
                'repeater' => is_array($value)
                    ? array_map(fn ($item) => is_array($item) ? self::sanitize($item, $field['fields'] ?? []) : [], $value)
                    : [],
                default => $value,
            };
        }

        return $clean;
    }

    /**
     * Cast data types according to field schema.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function cast(array $data, array $fields): array
    {
        $result = [];
        $fieldMap = self::indexByName($fields);

        foreach ($data as $name => $value) {
            if (! isset($fieldMap[$name])) {
                $result[$name] = $value;

                continue;
            }

            $type = $fieldMap[$name]['type'] ?? 'text';

            $result[$name] = match ($type) {
                'number', 'range' => is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : $value,
                'toggle' => (bool) $value,
                'group' => is_array($value) ? self::cast($value, $fieldMap[$name]['fields'] ?? []) : $value,
                'repeater' => is_array($value)
                    ? array_map(fn ($item) => is_array($item) ? self::cast($item, $fieldMap[$name]['fields'] ?? []) : $item, $value)
                    : $value,
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Sanitize HTML for richtext fields (whitelist safe tags).
     */
    private static function sanitizeHtml(string $html): string
    {
        return strip_tags(
            $html,
            '<p><br><strong><b><em><i><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><img><figure><figcaption><pre><code><hr><table><thead><tbody><tr><th><td>'
        );
    }

    /**
     * Index field definitions by name for O(1) lookup.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    private static function indexByName(array $fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            if (isset($field['name'])) {
                $map[$field['name']] = $field;
            }
        }

        return $map;
    }
}
