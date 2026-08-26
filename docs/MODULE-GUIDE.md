# Module Guide — build your first feature

A module is a self-contained feature (its own routes, model, migration, logic, tests). The fastest path is to **copy `modules/Example/` and rename**. This guide walks the anatomy and the steps.

## Anatomy of a module

`modules/Example/` is the canonical reference. Every file demonstrates one convention:

```
modules/Example/
├── module.json                              # manifest: name, namespace, provider, depends, actions
├── HookConstants.php                        # hook-name constants (typo-safe)
├── Providers/ExampleServiceProvider.php     # declares $morphMap/$serviceClass/$configKey — base does the rest
├── Models/Example.php                       # casts(), $attributes defaults, HasMeta, SoftDeletes, author() relation
├── Enums/ExampleStatus.php                  # backed enum + EnumHelpers
├── Services/ExampleService.php              # business logic, Transactional, fires hooks, slug dedup
├── Http/
│   ├── Controllers/ExampleController.php     # thin: FormRequest → Service → Resource
│   ├── Requests/{Store,Update,Search}ExampleRequest.php
│   └── Resources/ExampleResource.php
├── Database/
│   ├── Migrations/xxxx_create_examples_table.php   # auto-loaded
│   └── Factories/ExampleFactory.php
├── Routes/api.php                           # auto-mounted at /api/v1, behind auth:sanctum
├── config/settings.php                      # merged under config('module.example')
├── lang/{en,vi}/messages.php
└── Tests/Feature/ExampleCrudTest.php        # CRUD + hook + meta + dedup + soft-delete
```

## Steps

### 1. Copy & rename
```bash
cp -r modules/Example modules/Article
```
Then rename across the new folder: namespace `Modules\Example` → `Modules\Article`, class prefixes `Example*` → `Article*`, table `examples` → `articles`, morph alias `example` → `article`, lang key `example::` → `article::`, and update `module.json` (`name`, `alias`, `namespace`, `provider`).

### 2. Adjust the schema
Edit the migration (`Database/Migrations/..._create_articles_table.php`) and the model's `$fillable` / `casts()` / `$attributes` to your fields.

### 3. Enable it
`config/modules.php`:
```php
'enabled' => ['Example', 'Article'],
```

### 4. Migrate & verify
```bash
php artisan migrate          # the module migration auto-loads
php artisan route:list | grep articles
composer check               # pint + phpstan L8 + pest
```

## How auto-loading works (so the magic isn't magic)

You **don't** write `loadRoutesFrom`/`loadMigrationsFrom`. `BaseModuleServiceProvider` does it from the provider's folder location:

- `register()` → registers `$morphMap` (polymorphic alias) and binds `$serviceClass` as a singleton.
- `boot()` → loads `Database/Migrations`, `Routes/api.php` (prefix `api/v1`), `lang/`, and `config/settings.php` (under `$configKey`).

So a provider is just declarations:
```php
class ArticleServiceProvider extends BaseModuleServiceProvider
{
    protected array $morphMap = ['article' => Article::class];
    protected ?string $serviceClass = ArticleService::class;
    protected ?string $configKey = 'module.article';
}
```

## Patterns to copy from Example

- **Controller stays thin** — delegate to the service, return `ApiResponse` helpers (`collection`/`resource`/`success`). No queries, no `Hash::make` in controllers.
- **Service owns logic** — wrap writes in `$this->transactional(...)`, fire hooks via constants:
  ```php
  do_action(ArticleHooks::CREATED, $article);   // never a string literal
  ```
- **Validation** — one FormRequest per write (`Store`/`Update`) + a `Search` request for the index filters. Validate enums with `Rule::enum(ArticleStatus::class)`.
- **Resource** — shape the JSON; use `whenLoaded()` for relations.
- **Meta** — `$article->setMeta('key', $v)` for optional/plugin data only.

## Testing your module

Module tests live in `modules/{Name}/Tests/Feature` and are auto-discovered (`phpunit.xml` → `Modules` suite). Use Pest + `RefreshDatabase` + SQLite `:memory:`. Authenticate with `Sanctum::actingAs(User::factory()->create())`.

Always test:
- the CRUD endpoints (happy + validation failure),
- any **hook** you fire (assert the listener runs — static analysis can't see hooks),
- edge cases your service handles (e.g. Example tests slug de-duplication and soft delete).

Run: `./vendor/bin/pest --filter=Article`.

## Cross-module interaction (when you need it)

Never `use Modules\Other\...`. Instead:
- **React to another module**: `add_action(OtherHooks::SOMETHING, ...)` in your provider's `boot()`.
- **Expose data**: register in `EntityRegistry` / `ExportRegistry` / `NotificationRegistry`.
- **Inject relations**: declare them in `config('modules.bindings')` (the loader wires them via `resolveRelationUsing`). Prefer this only when relations genuinely span modules; otherwise keep relations explicit on the model.
