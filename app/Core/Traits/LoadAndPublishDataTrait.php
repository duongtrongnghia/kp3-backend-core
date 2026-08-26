<?php

declare(strict_types=1);

namespace App\Core\Traits;

use Closure;
use Illuminate\Support\Facades\Route;

trait LoadAndPublishDataTrait
{
    protected string $modulePath = '';

    protected string $moduleNamespace = '';

    /** @var array<string, mixed>|null */
    protected ?array $moduleManifest = null;

    /**
     * Set the base path for this module.
     */
    protected function setModulePath(string $path): static
    {
        $this->modulePath = $path;

        return $this;
    }

    /**
     * Set the namespace for this module (e.g., 'Tag', 'Post').
     */
    protected function setModuleNamespace(string $namespace): static
    {
        $this->moduleNamespace = $namespace;

        return $this;
    }

    /**
     * Load migrations from the module's Database/Migrations directory.
     */
    protected function loadMigrations(): static
    {
        $this->loadMigrationsFrom($this->modulePath.'/Database/Migrations');

        return $this;
    }

    /**
     * Load API routes from the module's Routes/api.php.
     */
    protected function loadRoutes(): static
    {
        $apiRoutes = $this->modulePath.'/Routes/api.php';
        if (file_exists($apiRoutes)) {
            Route::prefix('api/v1')
                ->middleware('api')
                ->group($apiRoutes);
        }

        $webRoutes = $this->modulePath.'/Routes/web.php';
        if (file_exists($webRoutes)) {
            Route::middleware('web')
                ->group($webRoutes);
        }

        // Public API routes — no auth, no Sanctum CSRF (visitor-facing endpoints).
        // Convention: modules/*/Routes/public.php. Middleware is config-driven so
        // the engine stays decoupled from app-specific middleware classes.
        $publicRoutes = $this->modulePath.'/Routes/public.php';
        if (file_exists($publicRoutes)) {
            Route::prefix('api/v1')
                ->middleware(config('modules.public_middleware', ['api']))
                ->group($publicRoutes);
        }

        return $this;
    }

    /**
     * Load Blade views from the module's Views directory.
     * Registered under namespace: e.g., 'post::blog.index'
     */
    protected function loadViews(): static
    {
        $viewsPath = $this->modulePath.'/Views';
        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, strtolower($this->moduleNamespace));
        }

        return $this;
    }

    /**
     * Load translations from the module's lang/ directory.
     * Access via namespaced key: __('tag::messages.create_success')
     */
    protected function loadTranslations(): static
    {
        $langPath = $this->modulePath.'/lang';
        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, strtolower($this->moduleNamespace));
        }

        return $this;
    }

    /**
     * Load helper functions from the module's Helpers/helpers.php.
     */
    protected function loadHelpers(): static
    {
        $helpersFile = $this->modulePath.'/Helpers/helpers.php';
        if (file_exists($helpersFile)) {
            require_once $helpersFile;
        }

        return $this;
    }

    /**
     * Expose forward relations this module can inject into a target model.
     * Override in module ServiceProvider.
     *
     * @param  string  $targetModel  Full class name of target model
     * @param  array<string, mixed>  $config  Config from theme bindings (e.g., {"pivot": ["sort_order"]})
     * @return array<string, Closure> ['relationName' => fn($model) => $model->morphToMany(...)]
     */
    public function getModelRelations(string $targetModel, array $config = []): array
    {
        return [];
    }

    /**
     * Expose inverse relations (e.g., $tag->posts via morphedByMany).
     * Override in N-N module ServiceProviders.
     *
     * @param  string  $targetModel  Full class name of the model that binds to this module
     * @param  array<string, mixed>  $config  Config from theme bindings
     * @return array<string, Closure> ['posts' => fn($model) => $model->morphedByMany(...)]
     */
    public function getInverseRelations(string $targetModel, array $config = []): array
    {
        return [];
    }

    /**
     * Set manifest from already-parsed module.json (injected by ModuleServiceProvider).
     *
     * @param  array<string, mixed>  $manifest
     */
    public function setManifest(array $manifest): static
    {
        $this->moduleManifest = $manifest;

        return $this;
    }

    /**
     * Get module.json manifest. Prefers injected data, falls back to file read.
     *
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        if ($this->moduleManifest === null) {
            $file = $this->modulePath.'/module.json';
            $rawContent = file_exists($file) ? file_get_contents($file) : false;
            $decoded = $rawContent !== false ? json_decode($rawContent, true) : null;
            $this->moduleManifest = is_array($decoded) ? $decoded : [];
        }

        return $this->moduleManifest;
    }

    /**
     * Action IDs for the Permission system. Single source of truth: module.json.
     *
     * @return array<int, array{id: string, description: string}>
     */
    public function getActions(): array
    {
        return $this->getManifest()['actions'] ?? [];
    }
}
