<?php

declare(strict_types=1);

namespace App\Core\Providers;

use App\Core\Cache\CacheService;
use App\Core\Cache\TagRegistry;
use App\Core\Hooks\HookConstants;
use App\Core\Hooks\HookManager;
use App\Core\Services\ModuleSettingService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Core service provider — boots the module architecture engine.
 *
 * Registers framework-agnostic singletons (hooks, cache, module settings),
 * the module loader, and fragment-cache Blade directives. No tenant/theme
 * coupling — module selection is config-driven (config/modules.php).
 */
class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Hook engine (actions + filters)
        $this->app->singleton(HookManager::class, fn () => new HookManager);

        // Tag-based cache system
        $this->app->singleton(TagRegistry::class);
        $this->app->singleton(CacheService::class);

        // Per-module key-value settings
        $this->app->singleton(ModuleSettingService::class);

        // Module system: scan modules/, resolve dependencies, register providers
        $this->app->register(ModuleServiceProvider::class);
    }

    public function boot(): void
    {
        // Hook debug mode (enable via core.hook_debug config, backed by HOOK_DEBUG env in config/core.php)
        if (config('app.debug') && config('core.hook_debug', false)) {
            HookManager::enableDebug();
        }

        $this->registerFragmentCacheDirectives();

        // Signal that the core has finished booting (modules may listen)
        do_action(HookConstants::CORE_BOOTED);
    }

    /**
     * Fragment cache Blade directives: @cache(section[, ttl]) ... @endcache.
     * Uses CacheService for tag-based tracking.
     */
    protected function registerFragmentCacheDirectives(): void
    {
        Blade::directive('cache', function (string $expression) {
            return "<?php
        \$__cacheArgs    = [{$expression}];
        \$__cacheSection = \$__cacheArgs[0];
        \$__cacheKey     = 'fragment_' . md5(\$__cacheSection);
        \$__cacheTtl     = \$__cacheArgs[1] ?? config('core.cache.fragment_ttl', 1800);
        \$__cs           = app(\App\Core\Cache\CacheService::class);
        \$__hit          = \$__cs->get(\$__cacheKey);
        if (\$__hit !== null) { echo \$__hit; } else { ob_start();
    ?>";
        });

        Blade::directive('endcache', function () {
            return "<?php
        \$__output = ob_get_clean();
        \$__cs->put(\$__cacheKey, \$__output, \$__cacheTtl, ['fragments', 'fragment:' . \$__cacheSection]);
        echo \$__output;
        }
    ?>";
        });
    }
}
