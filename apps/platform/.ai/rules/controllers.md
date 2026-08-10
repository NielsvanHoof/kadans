---
paths:
  - 'app/*/Http/Controllers/**'
---

# Controllers

## Controllers stay thin: authorize, call Action/Query, render
Three steps only: `$this->authorize(...)`, call an Action or Query `handle()`, return `Inertia::render()` / redirect / download / `response()->json()`.

Multi-method controllers with explicit verb routes. No `Route::resource`/`apiResource`. Middleware goes on route groups in `routes/*.php`, never `HasMiddleware` or `#[Middleware]` on the controller.

There is no top-level `app/Http` — HTTP lives inside each domain.

Intentional cross-tenant lookups use `TenantOwnedModel::queryWithoutTenantScope()`, never `withoutGlobalScopes()` (greppable by design).
