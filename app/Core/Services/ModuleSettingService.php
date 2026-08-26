<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Cache\CacheService;
use App\Core\Models\ModuleSetting;

class ModuleSettingService
{
    /**
     * L1: Per-request memory cache (fastest, same request only).
     *
     * @var array<string, mixed>
     */
    protected array $memory = [];

    /**
     * Get a single setting value.
     * L1 (memory) → L2 (Redis) → L3 (DB).
     */
    public function get(string $module, string $key, mixed $default = null): mixed
    {
        $memKey = "{$module}.{$key}";

        // L1: per-request memory
        if (array_key_exists($memKey, $this->memory)) {
            return $this->memory[$memKey];
        }

        // L2: Redis cache
        $cacheKey = "module_setting:{$module}:{$key}";
        $cached = $this->cacheService()->get($cacheKey);

        if ($cached !== null) {
            $this->memory[$memKey] = $cached;

            return $cached;
        }

        // L3: Database
        $setting = ModuleSetting::where('module', $module)
            ->where('key', $key)
            ->first();

        $value = $setting ? $setting->casted_value : $default;

        // Store in L2 (Redis) + L1 (memory)
        $this->cacheService()->put(
            $cacheKey,
            $value,
            config('core.cache.module_ttl', 3600),
            ["module_settings:{$module}"]
        );
        $this->memory[$memKey] = $value;

        return $value;
    }

    /**
     * Set a setting value. Invalidates L1 + L2 cache for the module.
     */
    public function set(string $module, string $key, mixed $value, string $type = 'string'): void
    {
        ModuleSetting::updateOrCreate(
            ['module' => $module, 'key' => $key],
            ['value' => ModuleSetting::encodeValue($value, $type), 'type' => $type]
        );

        // Invalidate L2 (all settings for this module)
        $this->cacheService()->invalidateTag("module_settings:{$module}");

        // Update L1 for current request
        $this->memory["{$module}.{$key}"] = $value;
    }

    /**
     * Get all settings for a module.
     *
     * @return array<string, mixed>
     */
    public function getAll(string $module): array
    {
        $cacheKey = "module_settings_all:{$module}";
        $cached = $this->cacheService()->get($cacheKey);

        if ($cached !== null) {
            // Populate L1 memory
            foreach ($cached as $k => $v) {
                $this->memory["{$module}.{$k}"] = $v;
            }

            return $cached;
        }

        $settings = ModuleSetting::where('module', $module)->get();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->key] = $setting->casted_value;
            $this->memory["{$module}.{$setting->key}"] = $setting->casted_value;
        }

        $this->cacheService()->put(
            $cacheKey,
            $result,
            config('core.cache.module_ttl', 3600),
            ["module_settings:{$module}"]
        );

        return $result;
    }

    /**
     * Delete a setting. Invalidates L1 + L2 cache.
     */
    public function delete(string $module, string $key): void
    {
        ModuleSetting::where('module', $module)->where('key', $key)->delete();

        $this->cacheService()->invalidateTag("module_settings:{$module}");
        unset($this->memory["{$module}.{$key}"]);
    }

    /**
     * Seed default settings from module's config/settings.php.
     * Only inserts missing keys — never overwrites admin customizations.
     */
    /**
     * @param  array<string, array{type?: string, value?: mixed}>  $defaults
     */
    public function seedDefaults(string $module, array $defaults): int
    {
        $seeded = 0;
        foreach ($defaults as $key => $config) {
            $exists = ModuleSetting::where('module', $module)
                ->where('key', $key)
                ->exists();

            if (! $exists) {
                $type = $config['type'] ?? 'string';
                $this->set($module, $key, $config['value'] ?? null, $type);
                $seeded++;
            }
        }

        return $seeded;
    }

    /**
     * Clear per-request memory cache.
     */
    public function clearCache(): void
    {
        $this->memory = [];
    }

    protected function cacheService(): CacheService
    {
        return app(CacheService::class);
    }
}
