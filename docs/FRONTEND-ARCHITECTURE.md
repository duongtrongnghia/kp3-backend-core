# Frontend Architecture (reference) — React SPA

Recommended architecture for the React app that consumes this backend. This is a **simple single-bundle SPA** (feature-folder based), not a micro-frontend. The FE is a separate repo/app; this doc is a convention reference only — no FE code ships in this starter.

## Stack

- **React 19** + **TypeScript** (strict, no `any`) + **Vite**
- **TailwindCSS** for styling
- **react-router** (createBrowserRouter, lazy routes)
- **TanStack Query** for server state
- **Zustand** for client/UI state (optional)
- **axios** (cookie auth — see [FRONTEND-INTEGRATION.md](FRONTEND-INTEGRATION.md))
- **react-hook-form + yup** for forms
- **i18next** (en/vi)
- **Vitest + Testing Library + MSW** for tests; **ESLint + Prettier + jsx-a11y**

## Folder structure

```
src/
├── app/          # providers, layouts, pages, router (createBrowserRouter)
├── features/     # one folder per feature: components, hooks, services, schema.ts, types
├── components/   # ui/ (primitives)  shared/ (composed, reusable)
├── hooks/        utils/        constants/ (endpoints, routes)
├── middleware/   # route guards
├── services/     # axios instance + interceptors
├── i18n/         styles/
```

## Conventions

- **Routing & guards**: three groups — `GhostGuard` (unauthenticated only), `AuthGuard` (authenticated), public. Lazy-load route components. No dynamic module loader.
- **State**: server data → TanStack Query (stable `queryKey`, explicit invalidation). UI/client state → Zustand or React context. Never store server data in Zustand.
- **API layer**: a single axios instance with `withCredentials`, the `X-XSRF-TOKEN` handling, and the error interceptor mapping 401/419/422/429 (see integration doc). Endpoint paths centralised in `constants/endpoints.ts`.
- **Auth**: cookie session only. `AuthGuard` bootstraps by calling `GET /api/v1/user`. Never persist tokens in `localStorage`.
- **Forms**: react-hook-form + a yup `schema.ts` per feature; render field errors from the 422 `errors` map.
- **i18n**: namespaced keys, en/vi parity.
- **Quality**: kebab-case filenames, PascalCase components, files < ~200 LOC, Tailwind-only (no ad-hoc CSS).

## Request flow

```
React component
  → TanStack Query (useQuery/useMutation)
    → axios instance (withCredentials, X-XSRF-TOKEN)
      → Laravel /api/v1/* (auth:sanctum cookie)
        → Controller → Service → Resource → JSON { success, data }
```

## Scaling note

If a project later needs independently built/deployed frontend modules (multiple teams), graduate to a shell + module-federation architecture. That is intentionally **out of scope** for this starter — keep it a single bundle until the complexity is justified.
