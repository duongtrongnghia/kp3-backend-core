<?php

declare(strict_types=1);

namespace Modules\Example\Providers;

use App\Core\Providers\BaseModuleServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Modules\Example\Models\Example;
use Modules\Example\Services\ExampleService;

/**
 * Example module provider.
 *
 * Extends BaseModuleServiceProvider — the base auto-handles morphMap,
 * service singleton, and resource loading (migrations/routes/lang/config).
 * A module only declares properties + optional registerModule()/bootModule().
 */
class ExampleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Polymorphic morph alias → model. Enables meta/relations by alias.
     *
     * @var array<string, class-string<Model>>
     */
    protected array $morphMap = [
        'example' => Example::class,
    ];

    /**
     * Service bound as a singleton in the container.
     *
     * @var class-string|null
     */
    protected ?string $serviceClass = ExampleService::class;

    /**
     * Config key — merges config/settings.php under config('module.example').
     */
    protected ?string $configKey = 'module.example';
}
