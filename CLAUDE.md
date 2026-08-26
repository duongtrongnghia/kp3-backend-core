# CLAUDE.md — AI agent guide for this starter

You are a senior Laravel engineer working in a **modular Laravel 12 backend starter**. Follow these rules exactly.

## Source of truth

- **Architecture:** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — read first to understand the engine + request flow.
- **Conventions:** [docs/CODE-STANDARDS.md](docs/CODE-STANDARDS.md) — read before writing code.
- **Building a feature:** [docs/MODULE-GUIDE.md](docs/MODULE-GUIDE.md) — step-by-step.
- **Frontend pairing:** [docs/FRONTEND-ARCHITECTURE.md](docs/FRONTEND-ARCHITECTURE.md), [docs/FRONTEND-INTEGRATION.md](docs/FRONTEND-INTEGRATION.md).
- **Reference module:** `modules/Example/` — the canonical full pattern. Copy it to scaffold new modules.

## Non-negotiable quality gates

Run before considering any task done:

```bash
composer check   # pint --test + phpstan (level 8) + pest
```

- `declare(strict_types=1)` in every PHP file.
- PHPStan **level 8**, **no baseline**, no blanket `ignoreErrors`, no `@phpstan-ignore` unless a single justified framework-limitation line. **Fix the type, don't suppress.**
- Pint must pass (Laravel preset + strict types + ordered imports).
- Tests pass (Pest, SQLite `:memory:`).

## Architecture rules

1. Controllers are thin: FormRequest in, Service call, `ApiResponse` out. **No business logic, no `Hash::make`, no queries in controllers.**
2. Business logic in Services; wrap writes in `transactional()`; fire hooks (`do_action`) for cross-module reactions.
3. No `app/Actions/` — fold single-use-case logic into Service methods.
4. No querying around schema drift (`Schema::hasColumn` in queries is banned).
5. Modules are headless and decoupled — communicate via hooks/registries, never cross-module class imports.

## Adding a module

1. Copy `modules/Example/` → `modules/{Name}/`, rename namespace/classes/`module.json`.
2. Add `{Name}` to `config/modules.php` → `enabled`.
3. `php artisan migrate` && `composer check`.

The `BaseModuleServiceProvider` auto-loads migrations/routes/lang/config and registers morph map + service singleton — declare properties, don't re-implement loaders.

## Workflow

UNDERSTAND → SCAN existing code/patterns → implement following `Example` → `composer check` → fix until green. Don't introduce new dependencies without need (YAGNI/KISS/DRY).

## Auth

Cookie-based Sanctum SPA auth is built in (`app/Http`, `app/Services`, `app/Models/User`). Role logic uses the `App\Enums\UserRole` enum (`isSuperAdmin()`, `getRoleLevel()`) — there is **no** separate permission module. Don't reintroduce one unless asked.
