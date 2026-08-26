# Code Standards

The conventions every contributor (and AI agent) must follow in this project.

## Golden rules

1. Every PHP file starts with `declare(strict_types=1);` (Pint enforces).
2. PHPStan **level 8**, no baseline, no blanket `ignoreErrors`. Fix the type, don't suppress.
3. Controllers contain **zero business logic** — they validate (FormRequest), call a Service, and return via `ApiResponse`.
4. Never query around schema drift (no `Schema::hasColumn()` inside queries). The schema matches migrations.
5. Modules are headless API + logic; cross-module access goes through hooks/registries, never direct imports.

## Layered architecture

```
Controller → Service → Model
   │           │
   │           └─ business logic, transactions, hooks (App\Traits\Transactional)
   └─ FormRequest validation + ApiResponse formatting + API Resource
```

- **Controller**: thin. Constructor-inject the Service. Type-hint FormRequests. Return `JsonResponse` via `ApiResponse` (`success`/`error`/`resource`/`collection`).
- **Service**: all writes wrapped in `$this->transactional(fn () => …)`. Fire hooks (`do_action('thing.created', $model)`) so other modules react without coupling.
- **Model**: `casts()` method (Laravel 12 style), `@property` docblocks for IDE/PHPStan, relations annotated `@return BelongsTo<Related, $this>`.
- **No `app/Actions/`** — single-use-case logic lives as a Service method (avoids the Action-vs-Service ambiguity).

## API responses

Use the `App\Traits\ApiResponse` trait — never hand-build `response()->json()` in controllers.
Shape: `{ success, status, message, data | errors }`. List endpoints use `collection()`, single use `resource()`.

## Validation

Always a `FormRequest` (one per write action: `Store*`, `Update*`, plus `Search*` for index filters). No inline validation in controllers. Validate enums with `Rule::enum(MyEnum::class)`.

## Error handling (centralised)

All exception→HTTP mapping lives in `bootstrap/app.php` `withExceptions()`: 401 (unauthenticated), 419 (CSRF), 422 (validation `{errors}`), 404 (model/route), 429 (throttle), 500. Throw exceptions in services; never format error responses by hand.

## Modules

Folder pattern (see `modules/Example/`):

```
modules/{Name}/
├── module.json                  # manifest: name, namespace, provider, depends, actions
├── Providers/{Name}ServiceProvider.php   # extends App\Core\Providers\BaseModuleServiceProvider
├── HookConstants.php             # hook-name constants (required if the module fires hooks)
├── Models/        Enums/        Services/
├── Http/{Controllers,Requests,Resources}/
├── Database/{Migrations,Factories}/
├── Routes/api.php               # loaded under /api/v1 automatically
├── config/settings.php          # merged under config('module.{alias}')
├── lang/{en,vi}/messages.php
└── Tests/Feature/
```

- The ServiceProvider only declares properties (`$morphMap`, `$serviceClass`, `$configKey`) — the base auto-loads migrations/routes/lang/config and registers the morph map + service singleton.
- Enable via `config/modules.php` → `enabled`. Dependencies resolve via `module.json` `depends` (topological order).
- Naming: module folder + classes **PascalCase**; morph alias + table **snake_case**; helper functions **snake_case**.

## Extensibility primitives (`app/Core/`)

- **Hooks**: `do_action`/`add_action`, `apply_filters`/`add_filter`. **Hook names MUST be class constants** (a `HookConstants` class per module), never string literals — enforced by `App\Tools\PHPStan\HookConstantRule` (a typo'd string hook fails silently). Every hook a module fires should have a wiring test (see `modules/Example` — the `created` hook is asserted in its feature test).
- **Meta (EAV)** — `HasMeta` trait (`$model->setMeta/getMeta`) or universal `meta_set/meta_get`. **Escape hatch only.** Use for optional/plugin/3rd-party data attached without a migration. **Do NOT** store core domain attributes in meta — those get real columns + types + indexes + FKs. EAV has no typed columns, no FKs, and invites N+1; reserve it for the long tail, not the model's identity.
- **Module settings**: `module_setting('alias', 'key')` (DB → config fallback).
- **Cache**: tag-based via `CacheService`; invalidate through hooks. Fragment cache: `@cache(...) … @endcache` Blade directives.
- **Registries**: `EntityRegistry`, `ExportRegistry`, `NotificationRegistry` for cross-module contracts.
- **DataTable**: `DataTableEngine` for filter/search/paginate query building.

## Testing

- **Pest 3**. Feature test per module under `modules/*/Tests/Feature` (auto-discovered, see `phpunit.xml` + `tests/Pest.php`).
- `RefreshDatabase`, SQLite `:memory:`. Authenticate with `Sanctum::actingAs(User::factory()->create())`.
- Cover happy path + edge cases (e.g. slug dedup, soft delete, hook firing). Don't mock what you can run.

## i18n

`lang/{en,vi}/messages.php` per module. Reference as `__('alias::messages.key')`. Keep en/vi in parity.

## Security

- Auth: Sanctum cookie SPA (CSRF-protected). Never store tokens in the browser.
- Throttle auth endpoints. `SecurityHeaders` middleware on all responses.
- Escape output; sanitize CSV exports (`CsvSanitizer`). Validate + whitelist SQL identifiers if ever building dynamic SQL.

## Naming summary

| Thing | Style |
|---|---|
| PHP class / module folder | PascalCase |
| DB table / column / morph alias | snake_case |
| Helper function / route segment | snake_case / kebab |
| File (non-PHP: js/ts/sh) | kebab-case |

Keep files under ~200 LOC; split when larger.
