<?php

declare(strict_types=1);

namespace App\Core\Providers;

use App\Core\Cache\CacheInvalidationListener;
use App\Core\Traits\LoadAndPublishDataTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Base class for all module ServiceProviders.
 *
 * Modules extend this and declare properties — base class auto-handles:
 * - modulePath/namespace derivation
 * - morphMap registration
 * - config merging
 * - service singleton
 * - resource loading (migrations, routes, helpers, translations, views)
 * - cache invalidation (3 standard hooks)
 *
 * Module-specific logic goes in registerModule() and bootModule().
 */
abstract class BaseModuleServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    // ── Properties — module declares ─────────────────────────

    /**
     * Morph map entries. e.g., ['post' => Post::class]
     * Auto-registered in register(). CRITICAL for polymorphic relations.
     *
     * @var array<string, class-string<Model>>
     */
    protected array $morphMap = [];

    /**
     * Service class to bind as singleton. e.g., PostService::class
     * Auto-registered in register() if set.
     */
    protected ?string $serviceClass = null;

    /**
     * HookConstants class. e.g., \Modules\Post\HookConstants::class
     * When set, base class auto-registers cache invalidation for 3 standard hooks.
     */
    protected ?string $hookConstantsClass = null;

    /**
     * Config key for mergeConfigFrom. e.g., 'module.post'
     * Looks for config/settings.php in module directory.
     */
    protected ?string $configKey = null;

    // ── Dependency declarations ─────────────────────────

    /**
     * Required modules — deploy blocked if missing.
     * e.g., ['payment', 'customer']
     *
     * @var string[]
     */
    protected array $requires = [];

    /**
     * Optional modules — warning if missing, not blocking.
     * e.g., ['coupon', 'tax', 'shipping']
     *
     * @var string[]
     */
    protected array $optional = [];

    /**
     * Conditional requires — required only when a config is enabled.
     * e.g., ['shipping' => 'module.order.enable_shipping']
     *
     * @var array<string, string>
     */
    protected array $conditionalRequires = [];

    /**
     * True for satellite modules that need a binding target.
     * e.g., Tag, Category, Comment, Review, Revision
     */
    protected bool $needsTarget = false;

    // ── register() — auto setup ──────────────────────────────

    public function register(): void
    {
        $this->autoSetModulePath();

        if (! empty($this->morphMap)) {
            Relation::morphMap($this->morphMap);
        }

        if ($this->configKey) {
            $configFile = $this->modulePath.'/config/settings.php';
            if (file_exists($configFile)) {
                $this->mergeConfigFrom($configFile, $this->configKey);
            }
        }

        if ($this->serviceClass) {
            $this->app->singleton($this->serviceClass);
        }

        $this->registerModule();
    }

    // ── boot() — auto load + hooks ──────────────────────────

    public function boot(): void
    {
        $this->loadMigrations()
            ->loadRoutes();

        if (file_exists($this->modulePath.'/Helpers/helpers.php')) {
            $this->loadHelpers();
        }

        if (is_dir($this->modulePath.'/lang')) {
            $this->loadTranslations();
        }

        if (is_dir($this->modulePath.'/views')) {
            $this->loadViews();
        }

        if ($this->hookConstantsClass) {
            $this->autoRegisterCacheInvalidation();
        }

        $this->bootModule();
    }

    // ── Template methods — module overrides ──────────────────

    /**
     * Module-specific registration (extra singletons, factories, bindings).
     * Called after auto setup in register().
     */
    protected function registerModule(): void {}

    /**
     * Module-specific boot logic (theme resolvers, menu hooks, capabilities).
     * Called after auto loading + cache invalidation in boot().
     */
    protected function bootModule(): void {}

    /**
     * Cache invalidation tags for this module's entities.
     * Override for custom tags (e.g., Post adds 'feed', 'sitemap').
     *
     * @return string[] Tag names to invalidate
     */
    protected function getCacheInvalidationTags(): array
    {
        $entity = array_key_first($this->morphMap);

        return $entity ? [Str::plural($entity)] : [];
    }

    // ── Dependency getters (for ValidateDependenciesCommand) ──

    /** @return string[] */
    public function getRequires(): array
    {
        return $this->requires;
    }

    /** @return string[] */
    public function getOptional(): array
    {
        return $this->optional;
    }

    /** @return array<string, string> */
    public function getConditionalRequires(): array
    {
        return $this->conditionalRequires;
    }

    public function getNeedsTarget(): bool
    {
        return $this->needsTarget;
    }

    public function getModulePath(): string
    {
        return $this->modulePath;
    }

    // ── Private auto logic ───────────────────────────────────

    /**
     * Derive modulePath and moduleNamespace from the ServiceProvider class location.
     * e.g., Modules\Post\Providers\PostServiceProvider → path=modules/Post, namespace=Post
     */
    private function autoSetModulePath(): void
    {
        $ref = new ReflectionClass(static::class);
        $fileName = $ref->getFileName();
        // ReflectionClass::getFileName() returns false only for internal classes;
        // module ServiceProviders are always user-defined files, so this is safe.
        $providerDir = dirname((string) $fileName);

        $this->setModulePath(dirname($providerDir))
            ->setModuleNamespace(basename(dirname($providerDir)));
    }

    /**
     * Auto-register cache invalidation for 3 standard hooks:
     * - {ENTITY}_SAVED → onEntitySaved
     * - BEFORE_{ENTITY}_DELETE → onEntitySaved (same invalidation)
     * - BEFORE_BULK_{ENTITY}_DELETE → onContentSaved (global flush)
     *
     * Only hooks that exist as constants in the hookConstantsClass are registered.
     * Modules with non-standard hook names should override bootModule() and register manually.
     */
    private function autoRegisterCacheInvalidation(): void
    {
        $hookClass = $this->hookConstantsClass;
        $tags = $this->getCacheInvalidationTags();
        $entity = array_key_first($this->morphMap);

        if (! $entity) {
            return;
        }

        $upper = strtoupper($entity);

        // {ENTITY}_SAVED
        $savedConst = "{$hookClass}::{$upper}_SAVED";
        if (defined($savedConst)) {
            add_action(constant($savedConst), function ($model) use ($entity, $tags) {
                CacheInvalidationListener::onEntitySaved($model, $entity, $tags);
            });
        }

        // BEFORE_{ENTITY}_DELETE
        $deleteConst = "{$hookClass}::BEFORE_{$upper}_DELETE";
        if (defined($deleteConst)) {
            add_action(constant($deleteConst), function ($model) use ($entity, $tags) {
                CacheInvalidationListener::onEntitySaved($model, $entity, $tags);
            });
        }

        // BEFORE_BULK_{ENTITY}_DELETE
        $bulkConst = "{$hookClass}::BEFORE_BULK_{$upper}_DELETE";
        if (defined($bulkConst)) {
            add_action(constant($bulkConst), function () {
                CacheInvalidationListener::onContentSaved(null);
            });
        }
    }
}
