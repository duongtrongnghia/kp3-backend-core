<?php

declare(strict_types=1);

namespace App\Core\Registry;

/**
 * Static registry for notification type metadata.
 *
 * Modules register notification types at boot time with default channels.
 * Admin can override channels per type via module_setting('notification', 'channels.{key}').
 *
 * Usage:
 *   NotificationRegistry::register('order.placed', [
 *       'label'        => 'Đơn hàng mới',
 *       'description'  => 'Gửi khi khách đặt hàng thành công',
 *       'channels'     => ['mail', 'database'],
 *       'configurable' => true,
 *   ]);
 *
 *   NotificationRegistry::getChannels('order.placed');  // ['mail', 'database'] or admin override
 */
class NotificationRegistry
{
    /** @var array<string, array<string, mixed>> key → config */
    private static array $types = [];

    /**
     * Register a notification type.
     *
     * @param  string  $key  Dot-notated key (e.g. 'order.placed', 'form.submission_received')
     * @param  array<string, mixed>  $config  {label, description, channels, configurable}
     */
    public static function register(string $key, array $config): void
    {
        self::$types[$key] = $config;
    }

    /**
     * Get all registered notification types.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$types;
    }

    /**
     * Get config for a specific notification type.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::$types[$key] ?? null;
    }

    /**
     * Get channels for a notification type.
     * Merges: registry defaults ← admin DB overrides.
     *
     * @return string[] Channel names (e.g. ['mail', 'database'])
     */
    public static function getChannels(string $key): array
    {
        $config = self::$types[$key] ?? null;

        if (! $config) {
            return ['mail'];
        }

        $defaults = $config['channels'] ?? ['mail'];

        // Admin override via module_setting
        if (($config['configurable'] ?? false) && function_exists('module_setting')) {
            $override = module_setting('notification', "channels.{$key}");

            if (is_array($override)) {
                return $override;
            }
        }

        return $defaults;
    }

    /**
     * Get all types with their current channel config (for admin UI).
     *
     * @return array<string, array<string, mixed>> key → {label, description, channels, configurable}
     */
    public static function allWithChannels(): array
    {
        $result = [];

        foreach (self::$types as $key => $config) {
            $result[$key] = array_merge($config, [
                'channels' => static::getChannels($key),
            ]);
        }

        return $result;
    }

    public static function reset(): void
    {
        self::$types = [];
    }
}
