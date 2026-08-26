# Architecture

A guided tour of how this starter is put together, so a new dev can be productive fast. Read this once, then keep [CODE-STANDARDS.md](CODE-STANDARDS.md) open while coding.

## Mental model

Two things live side by side:

1. **The app** — a normal Laravel 12 API (auth, users, etc.) in `app/`.
2. **A small module engine** — `app/Core/` — that lets you bolt on self-contained feature modules in `modules/` without touching the app.

You add features as **modules**. The engine wires them in. The app and the engine never need to change when you ship a new feature.

## Request lifecycle

```
HTTP request
  │
  ▼  bootstrap/app.php
     ├─ statefulApi()            → Sanctum cookie session (SPA)
     ├─ middleware (api group)   → SecurityHeaders, SetLocale, TrackSessionInfo
     └─ withExceptions()         → centralised JSON errors (401/419/422/404/429/500)
  │
  ▼  Route  (routes/api.php  +  modules/*/Routes/api.php auto-mounted at /api/v1)
  │
  ▼  Controller (thin)
     ├─ FormRequest        → validation
     ├─ Service            → business logic, transactions, hooks
     │     └─ Model        → Eloquent (+ casts, HasMeta)
     └─ ApiResponse + JsonResource → { success, status, message, data }
```

Every layer has one job. The controller never holds business logic; the service never formats HTTP; the model never validates input. See [CODE-STANDARDS.md](CODE-STANDARDS.md#layered-architecture).

## The module engine (`app/Core/`)

### How a module loads

```
config/modules.php  ('enabled' => ['Example', ...])
        │
        ▼
CoreServiceProvider::register()
        │  registers ↓
        ▼
ModuleServiceProvider::register()
        ├─ read config('modules.enabled')         (testing env: load ALL)
        ├─ scanModules()   → read each modules/*/module.json
        ├─ resolveDependencies()  → topological sort by module.json "depends"
        └─ for each module: app->register({Name}ServiceProvider)
                                   │
                                   ▼  extends BaseModuleServiceProvider
                          register(): morphMap + config + service singleton
                          boot():     migrations + routes + lang + config (auto)
```

Key files:

| File | Role |
|---|---|
| `app/Core/Providers/CoreServiceProvider` | Boots the engine: binds hook/cache/settings singletons, registers the module loader, registers fragment-cache Blade directives, fires `CORE_BOOTED`. |
| `app/Core/Providers/ModuleServiceProvider` | The loader: scan → validate → dependency sort → register each module provider. Also wires cross-module relations from `config('modules.bindings')`. |
| `app/Core/Providers/BaseModuleServiceProvider` | Every module provider extends this. A module just **declares properties** (`$morphMap`, `$serviceClass`, `$configKey`); the base auto-registers the morph map + service singleton and auto-loads migrations/routes/lang/config. |
| `app/Core/Registry/ModuleRegistry` | Tracks loaded modules → `is_module_loaded('Example')`. |

A module provider is therefore tiny — see `modules/Example/Providers/ExampleServiceProvider`.

### Why config-driven (not theme/auto-scan)

Modules are enabled explicitly in `config/modules.php`. This is intentional: you can see exactly what's active, disable a module without deleting it, and keep the loader decoupled from any UI/theme concern.

## Extensibility primitives

These let modules cooperate **without importing each other** (no `use Modules\Other\...`).

### Hooks (events)  — `app/Core/Hooks/`
WordPress-style actions + filters.

```php
// Producer (a service):
do_action(ExampleHooks::CREATED, $example);

// Consumer (any other module's ServiceProvider::boot or a listener):
add_action(ExampleHooks::CREATED, fn ($example) => /* react */);
```

- **Hook names are class constants, never string literals** — enforced by `App\Tools\PHPStan\HookConstantRule` (a typo'd string hook fails silently). Each module declares a `HookConstants` class.
- `apply_filters($name, $value)` transforms a value through registered filters.

### Meta / EAV — `app/Core/Traits/HasMeta` + `meta_*()` helpers
Attach arbitrary data to a model with no migration:

```php
$example->setMeta('external_id', 'abc');   // model uses HasMeta
meta_set($anyModel, 'plugin_field', 123);  // works on ANY Eloquent model
```

**Escape hatch only** — for optional/plugin data. Core domain attributes get real columns. (See [CODE-STANDARDS.md](CODE-STANDARDS.md#extensibility-primitives-appcore).)

### Module settings — `module_setting()`
Per-module key/value config, DB-backed with a `config('module.{alias}')` fallback:

```php
$perPage = module_setting('example', 'per_page', 15);
```

### Registries — `app/Core/Registry/`
Typed extension points modules register into: `EntityRegistry` (resolve/search entities by type), `ExportRegistry`, `NotificationRegistry`.

### Cache — `app/Core/Cache/`
Tag-based cache (`CacheService`) invalidated via hooks; `@cache(...) … @endcache` Blade directives for fragments.

### Shared engines
`DataTable/` (filter/search/paginate query builder), `Export/` (CSV/XLSX + injection-safe), `Report/`, `Schema/` (JSON-schema validation).

## Authentication

Cookie-based Sanctum SPA auth (see [FRONTEND-INTEGRATION.md](FRONTEND-INTEGRATION.md)). Highlights:

- Full flows: register → OTP → login → 2FA (TOTP/email/phone) → logout; password reset; social login; device tracking; admin user management; invitations.
- **No permission package** — role logic is the `App\Enums\UserRole` enum (`isAdmin()`, `level()`) surfaced on the model as `User::isSuperAdmin()` / `getRoleLevel()`.
- `User` uses `HasApiTokens`, so bearer-token auth for mobile can be switched on without new infra ([MOBILE-TOKEN-AUTH.md](MOBILE-TOKEN-AUTH.md)).

## Error handling

All exception → HTTP mapping is centralised in `bootstrap/app.php` `withExceptions()`. Throw exceptions in services; never build error responses by hand. The JSON shape is uniform so the SPA can branch on `status` (401/419/422/429/...).

## Quality gates (the standards, enforced)

`composer check` = Pint (`strict_types` + style) + PHPStan **level 8, no baseline** + Pest. CI-ready. The bar is non-negotiable — fix types, don't suppress. Custom static rule: `HookConstantRule`. Full rationale in [CODE-STANDARDS.md](CODE-STANDARDS.md).

## Directory map

```
app/
├── Core/            ← module engine (Providers, Hooks, Cache, Meta, Registry, DataTable, Export, helpers.php)
├── Http/            ← Controllers (thin), Requests, Resources, Middleware
├── Services/        ← business logic (auth suite + Sms)
├── Models/  DTOs/  Enums/  Notifications/  Exceptions/  Traits/  Tools/PHPStan/
modules/
└── Example/         ← reference module — copy to scaffold a new one
config/  modules.php · core.php · cors.php · sanctum.php · sms.php · ...
bootstrap/app.php    ← middleware + centralised errors
docs/                ← you are here
tests/               ← Pest (auth flows); module tests live in modules/*/Tests
```

## Next

- Build your first feature: [MODULE-GUIDE.md](MODULE-GUIDE.md).
- Day-to-day conventions: [CODE-STANDARDS.md](CODE-STANDARDS.md).
