# Docs

Start here. Suggested reading order for a new developer:

| # | Doc | Read when |
|---|---|---|
| 1 | [ARCHITECTURE.md](ARCHITECTURE.md) | First — understand the layers, the module engine, and how a request flows. |
| 2 | [CODE-STANDARDS.md](CODE-STANDARDS.md) | Before writing code — the conventions + quality gates, enforced by `composer check`. |
| 3 | [MODULE-GUIDE.md](MODULE-GUIDE.md) | When building a feature — hands-on, copy `modules/Example`. |
| 4 | [FRONTEND-INTEGRATION.md](FRONTEND-INTEGRATION.md) | Wiring a React SPA — cookie auth flow + error contract. |
| 5 | [FRONTEND-ARCHITECTURE.md](FRONTEND-ARCHITECTURE.md) | Reference architecture for the React app (separate repo). |
| 6 | [MOBILE-TOKEN-AUTH.md](MOBILE-TOKEN-AUTH.md) | Optional — bearer-token auth for mobile/3rd-party. |

## TL;DR for the impatient

- It's Laravel 12. Features are **modules** in `modules/`, enabled in `config/modules.php`.
- Controller → Service → Model. Validation in FormRequests. Responses via `ApiResponse`.
- Modules talk to each other through **hooks** and **registries**, never direct imports.
- Quality bar: `composer check` (Pint + PHPStan L8 no-baseline + Pest) must be green.
- To add a feature: copy `modules/Example`, rename, enable, `php artisan migrate`, `composer check`.

The reference module `modules/Example/` is the living example of every convention.
