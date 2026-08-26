# Frontend Integration — React SPA + Sanctum cookie auth

This backend authenticates a React SPA via **Sanctum stateful cookies** (session-based, CSRF-protected). No bearer tokens in the browser. For mobile/3rd-party token auth see [MOBILE-TOKEN-AUTH.md](MOBILE-TOKEN-AUTH.md).

## Login flow

1. `GET /sanctum/csrf-cookie` → sets the `XSRF-TOKEN` cookie.
2. `POST /api/v1/auth/login` (with `withCredentials`) → server sets the session cookie.
3. Subsequent requests are authenticated automatically (cookie sent). `GET /api/v1/user` returns the current user.
4. `POST /api/v1/auth/logout` clears the session.

## Axios setup

```ts
import axios from 'axios';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  withCredentials: true,              // send/receive cookies
  withXSRFToken: true,                // axios reads XSRF-TOKEN cookie → X-XSRF-TOKEN header
  headers: { Accept: 'application/json' },
});

// Always hit csrf-cookie before the first mutating request:
export const ensureCsrf = () => axios.get('/sanctum/csrf-cookie', { withCredentials: true });
```

## Error contract (from `bootstrap/app.php`)

| Status | Meaning | FE action |
|---|---|---|
| 401 | Not authenticated | redirect to login |
| 419 | CSRF token expired/missing | call `/sanctum/csrf-cookie`, then retry |
| 422 | Validation failed | read `errors` map, show field errors |
| 429 | Throttled | back off, show retry-after |
| 404 | Not found | show not-found |
| 500 | Server error | generic error toast |

Suggested interceptor: on 419 → refetch csrf-cookie and retry once; on 401 → clear auth state and route to `/login`.

## Cookie config matrix

Set these in the backend `.env` to match your deployment topology:

| Deployment | `SESSION_DOMAIN` | `SESSION_SAME_SITE` | `SESSION_SECURE_COOKIE` |
|---|---|---|---|
| Dev via Vite proxy (same origin) | `null` | `lax` | `false` |
| Prod, same domain (`app.com` + `app.com/api`) | `.app.com` | `lax` | `true` |
| Prod, **cross domain** (`app.com` ↔ `api.com`) | `null` | `none` | `true` (HTTPS required) |

Also required:
- `SANCTUM_STATEFUL_DOMAINS` must include the FE origin (e.g. `localhost:5173`).
- `CORS_ALLOWED_ORIGINS` must list the exact FE origin (no `*` with credentials). CORS `supports_credentials` is already `true` (`config/cors.php`).

## Email links (verify / reset)

Verification and password-reset emails link to `FRONTEND_URL` — your React app handles the token route and calls the matching API endpoint.
