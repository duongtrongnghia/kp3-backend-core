<?php

declare(strict_types=1);

namespace App\Core\Providers;

use App\Core\Registry\ModuleRegistry;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Resolved module configs: ['Tag' => ['config' => [...], 'provider' => instance], ...]
     *
     * @var array<string, array{config: array<string, mixed>, provider: ServiceProvider|null}>
     */
    protected array $modules = [];

    /**
     * Module boot order (topologically sorted).
     *
     * @var string[]
     */
    protected array $bootOrder = [];

    /**
     * Tracks dynamically injected relation names per model class.
     * e.g., ['Modules\Post\Models\Post' => ['tags', 'categories', 'comments', 'revisions']]
     *
     * @var array<string, string[]>
     */
    protected static array $injectedRelations = [];

    public function register(): void
    {
        // Module selection is config-driven (config/modules.php → 'enabled').
        // In testing env, load ALL available modules so cross-module hook chains
        // (guarded by is_module_loaded()) can be exercised without per-test config.
        if ($this->app->environment('testing')) {
            $requires = array_keys($this->scanModules());
        } else {
            $requires = config('modules.enabled', []);
        }

        $this->validateModuleConfig($requires, config('modules.bindings', []));

        if (empty($requires)) {
            return;
        }

        // 1. Scan modules/ directory, load module.json
        $available = $this->scanModules();

        // 2. Validate all enabled modules exist before proceeding
        foreach ($requires as $moduleName) {
            if (! isset($available[$moduleName])) {
                throw new RuntimeException(
                    "Module [{$moduleName}] enabled in config/modules.php but not found in modules/. "
                    .'Available: ['.implode(', ', array_keys($available)).']'
                );
            }
        }

        // 3. Resolve dependencies recursively + topological sort
        $this->bootOrder = $this->resolveDependencies($requires, $available);

        // 4. Register each module's ServiceProvider (register phase)
        foreach ($this->bootOrder as $moduleName) {
            $config = $available[$moduleName];
            $providerClass = $config['provider'] ?? null;

            // FE-only modules (no provider) — register config only, skip ServiceProvider
            if (! $providerClass) {
                $this->modules[$moduleName] = [
                    'config' => $config,
                    'provider' => null,
                ];
                ModuleRegistry::register($moduleName);

                continue;
            }

            if (! class_exists($providerClass)) {
                throw new RuntimeException(
                    "Module [{$moduleName}] provider class [{$providerClass}] not found"
                );
            }

            $provider = $this->app->register($providerClass);

            // Inject already-parsed manifest so trait doesn't re-read module.json
            if (method_exists($provider, 'setManifest')) {
                $provider->setManifest($config);
            }

            $this->modules[$moduleName] = [
                'config' => $config,
                'provider' => $provider,
            ];
            ModuleRegistry::register($moduleName);
        }
    }

    public function boot(): void
    {
        if (empty($this->modules)) {
            return;
        }

        $bindings = config('modules.bindings', []);

        // Wire dynamic relations from config bindings
        foreach ($bindings as $targetModule => $sourceModulesConfig) {
            $targetModel = $this->resolveMainModel($targetModule);

            if (! $targetModel || ! class_exists($targetModel)) {
                Log::warning("Binding target model [{$targetModule}] could not be resolved");

                continue;
            }

            foreach ($sourceModulesConfig as $sourceModule => $config) {
                if (! isset($this->modules[$sourceModule])) {
                    continue;
                }

                $provider = $this->modules[$sourceModule]['provider'] ?? null;
                if (! $provider) {
                    continue;
                }

                // AD-100: source providers may implement ANY subset of:
                // getModelRelations / getInverseRelations / getContentHooks.
                // Snapshot-only bindings (e.g. Customer↔Order) implement only Hooks.
                $hasRelations = method_exists($provider, 'getModelRelations');
                $hasInverse = method_exists($provider, 'getInverseRelations');
                $hasHooks = method_exists($provider, 'getContentHooks');
                if (! $hasRelations && ! $hasInverse && ! $hasHooks) {
                    continue;
                }

                $sourceModel = $this->resolveMainModel($sourceModule);

                // Forward relations: e.g., Post -> tags
                // method_exists() used for runtime duck-typing; invoked via call_user_func to satisfy PHPStan
                // (ServiceProvider base class does not declare these optional extension methods).
                if ($hasRelations) {
                    /** @var array<string, Closure> $relations */
                    $relations = call_user_func([$provider, 'getModelRelations'], $targetModel, $config);
                    foreach ($relations as $name => $resolver) {
                        $targetModel::resolveRelationUsing($name, $resolver);
                        static::$injectedRelations[$targetModel][] = $name;
                    }
                }

                // Inverse relations: e.g., Tag -> posts
                if ($hasInverse && $sourceModel && class_exists($sourceModel)) {
                    /** @var array<string, Closure> $inverseRelations */
                    $inverseRelations = call_user_func([$provider, 'getInverseRelations'], $targetModel, $config);
                    foreach ($inverseRelations as $name => $resolver) {
                        $sourceModel::resolveRelationUsing($name, $resolver);
                    }
                }

                // Content hooks: register targeted {alias}.saved / {alias}.before_delete hooks
                // Only bound modules get listeners — no WP-style scan-all-listeners on every operation.
                if ($hasHooks) {
                    $alias = array_search($targetModel, Relation::morphMap())
                          ?: strtolower(class_basename($targetModel));
                    /** @var array<string, callable> $contentHooks */
                    $contentHooks = call_user_func([$provider, 'getContentHooks'], $targetModel, $config);
                    foreach ($contentHooks as $event => $callback) {
                        add_action("{$alias}.{$event}", $callback);
                    }
                }
            }
        }

        // Enforce morph map — all models must use aliases, not full class names
        Relation::requireMorphMap();
    }

    /**
     * Get dynamically injected relation names for a model class.
     * Used by controllers to eager-load without hardcoding module names.
     *
     * @return string[]
     */
    public static function getInjectedRelations(string $modelClass): array
    {
        return static::$injectedRelations[$modelClass] ?? [];
    }

    /**
     * Manually track an injected relation (useful for tests where theme bindings aren't loaded).
     */
    public static function trackInjectedRelation(string $modelClass, string $relationName): void
    {
        if (! in_array($relationName, static::$injectedRelations[$modelClass] ?? [])) {
            static::$injectedRelations[$modelClass][] = $relationName;
        }
    }

    /**
     * Scan modules/ directory, return available modules keyed by name.
     * Uses compiled cache (bootstrap/cache/modules.php) if available.
     * Run `php artisan module:cache` to generate, `php artisan module:clear` to purge.
     *
     * @return array<string, array<string, mixed>> ['Tag' => ['name' => 'Tag', 'alias' => 'tag', ...], ...]
     */
    protected function scanModules(): array
    {
        // Use compiled cache in production (generated by module:cache)
        $cachePath = base_path('bootstrap/cache/modules.php');
        if (file_exists($cachePath)) {
            return require $cachePath;
        }

        // Dev: scan filesystem
        $modulesPath = base_path('modules');

        if (! File::isDirectory($modulesPath)) {
            return [];
        }

        $available = [];

        foreach (File::directories($modulesPath) as $dir) {
            $manifestFile = $dir.'/module.json';
            if (! File::exists($manifestFile)) {
                continue;
            }

            $config = json_decode(File::get($manifestFile), true);
            if ($config === null || empty($config['name'])) {
                Log::warning("Invalid module manifest: {$manifestFile}");

                continue;
            }

            $available[$config['name']] = $config;
        }

        return $available;
    }

    /**
     * Resolve dependencies recursively and return topologically sorted boot order.
     * Detects circular dependencies.
     *
     * @param  string[]  $required  Module names required by theme
     * @param  array<string, array<string, mixed>>  $available  All scanned module configs
     * @return string[] Sorted module names
     */
    protected function resolveDependencies(array $required, array $available): array
    {
        $resolved = [];
        $visiting = [];

        foreach ($required as $moduleName) {
            $this->resolveModule($moduleName, $available, $resolved, $visiting);
        }

        return $resolved;
    }

    /**
     * @param  array<string, array<string, mixed>>  $available
     * @param  string[]  $resolved
     * @param  string[]  $visiting
     */
    protected function resolveModule(string $name, array $available, array &$resolved, array &$visiting): void
    {
        if (in_array($name, $resolved)) {
            return;
        }

        if (in_array($name, $visiting)) {
            throw new RuntimeException('Circular module dependency detected: '.implode(' -> ', $visiting)." -> {$name}");
        }

        if (! isset($available[$name])) {
            throw new RuntimeException(
                "Module [{$name}] (dependency) not found in modules/ directory"
            );
        }

        $visiting[] = $name;

        $depends = $available[$name]['depends'] ?? [];
        foreach ($depends as $dep) {
            $this->resolveModule($dep, $available, $resolved, $visiting);
        }

        array_pop($visiting);
        $resolved[] = $name;
    }

    /**
     * Resolve module name to its main Model class.
     * Convention: Modules\{Name}\Models\{Name}
     * e.g., 'Post' -> 'Modules\Post\Models\Post'
     */
    protected function resolveMainModel(string $moduleName): ?string
    {
        $class = "Modules\\{$moduleName}\\Models\\{$moduleName}";

        return class_exists($class) ? $class : null;
    }

    /**
     * Validate config/modules.php structure. Fail fast on invalid config.
     *
     * @param  array<int, mixed>  $enabled  Enabled module names (PascalCase)
     * @param  array<string, mixed>  $bindings  Polymorphic relation bindings
     */
    protected function validateModuleConfig(array $enabled, array $bindings): void
    {
        $errors = [];

        foreach ($enabled as $i => $name) {
            if (! is_string($name) || ! preg_match('/^[A-Z][a-zA-Z0-9]+$/', $name)) {
                $errors[] = "modules.enabled[{$i}] invalid module name: must be PascalCase string";
            }
        }

        foreach ($bindings as $target => $sources) {
            if (! is_array($sources)) {
                $errors[] = "modules.bindings.{$target} must be an array of source modules";
            }
        }

        if (! empty($errors)) {
            throw new RuntimeException(
                "Invalid config/modules.php:\n- ".implode("\n- ", $errors)
            );
        }
    }

    /**
     * Get all loaded modules (for artisan commands, APIs, etc.)
     *
     * @return array<string, array{config: array<string, mixed>, provider: ServiceProvider|null}>
     */
    public function getLoadedModules(): array
    {
        return $this->modules;
    }

    /**
     * Cached scan result (avoid re-scanning filesystem per call).
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $cachedAvailableModules = null;

    /**
     * Get all available modules from scan (before dependency resolution).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAvailableModules(): array
    {
        return $this->cachedAvailableModules ??= $this->scanModules();
    }
}
