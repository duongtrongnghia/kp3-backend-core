# Modular Laravel Starter

A clean Laravel 12 backend starter with a **module architecture**, a **full authentication suite** (cookie-based for React SPAs), and strict quality gates (PHPStan L8, Pint, Pest). Built to be copied into new projects.

## Stack

- **Laravel 12** · PHP 8.3 · MySQL (tests use SQLite `:memory:`)
- **Auth:** Laravel Sanctum (SPA cookie + optional bearer token), Socialite (social login), Google2FA (TOTP/email/phone 2FA)
- **Quality:** PHPStan **level 8** (no baseline), Laravel Pint (`declare(strict_types=1)` enforced), Pest 3

## What's inside

| Area | Where |
|---|---|
| Module engine (hooks, cache, meta, registries, DataTable, export) | `app/Core/` |
| Auth (controllers/services/requests/...) | `app/Http`, `app/Services`, `app/Models` |
| Cross-cutting traits (ApiResponse, Transactional, EnumHelpers) | `app/Traits/` |
| Sample module (full pattern reference) | `modules/Example/` |
| Centralised error handling | `bootstrap/app.php` |
| Docs | `docs/` |

> **New here? Start with [docs/](docs/README.md)** → [Architecture](docs/ARCHITECTURE.md) → [Code Standards](docs/CODE-STANDARDS.md) → [Module Guide](docs/MODULE-GUIDE.md). Frontend: [integration](docs/FRONTEND-INTEGRATION.md) + [architecture](docs/FRONTEND-ARCHITECTURE.md). Mobile/token: [doc](docs/MOBILE-TOKEN-AUTH.md).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# create the MySQL database named in .env (DB_DATABASE=cms_monolith), then:
php artisan migrate
php artisan serve   # http://localhost:8000
```

## Quality gates

```bash
composer lint     # pint --test  (style + strict_types)
composer stan     # phpstan level 8, no baseline
composer test     # pest
composer check    # all three
```

All three must stay green. **Do not** add a PHPStan baseline or lower the level.

## Create a new module (3 steps)

1. Copy `modules/Example/` → `modules/YourModule/`; rename namespace (`Modules\YourModule\…`), classes, and `module.json`.
2. Add `'YourModule'` to `config/modules.php` → `enabled`.
3. `php artisan migrate` (module migrations auto-load) and `composer check`.

Routes, migrations, lang, and config auto-load via `BaseModuleServiceProvider`. Full walkthrough: [docs/MODULE-GUIDE.md](docs/MODULE-GUIDE.md).

## Auth endpoints (high level)

Public: `POST /api/v1/auth/{register,login,verify-otp,resend-otp,verify-2fa,forgot-password,reset-password}`, social `GET /api/v1/auth/{provider}/redirect|callback`, `GET /sanctum/csrf-cookie`.
Authenticated (`auth:sanctum`): `GET /api/v1/user`, 2FA setup/confirm/disable, devices, profile, logout, admin user management.

See [docs/FRONTEND-INTEGRATION.md](docs/FRONTEND-INTEGRATION.md) for the React cookie login flow.
