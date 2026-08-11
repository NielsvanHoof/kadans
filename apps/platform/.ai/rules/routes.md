---
paths:
  - 'routes/**'
---

# Routes

## Tenant middleware must resolve before SubstituteBindings
Load-bearing ordering: `bootstrap/app.php` prepends `InitializeTenantFromSession`, `InitializeTenantFromRoute`, and `ResolveClientPortalGrant` **before** `SubstituteBindings`, so the tenant is current before route model binding applies `BelongsToTenant`. Breaking this silently unscopes bound models. `tests/Feature/Tenancy/TenantMiddlewarePriorityTest.php` guards it.

Domain route files declare relative paths only; the `workspaces.` name prefix and `{tenant}.{base}` domain are applied once in `routes/tenant.php`. Portal routes share that tenant domain under `/portal`.

Do not put staff workspace routes back under a `/workspaces/{tenant}` path prefix — tenancy is host-based.

Every route that streams or downloads document bytes carries `RequirePassword::using(passwordTimeoutSeconds: config('auth.document_access_timeout'))`. A retry/status route that streams nothing deliberately does not.

Rate limiting: named `RateLimiter::for()` limiters for Fortify auth flows (registered in `FortifyServiceProvider`); inline `throttle:N,1` on feature and portal routes. Fortify has no config hook for password-reset routes — those are throttled by attaching middleware to the named routes after Fortify registers them.

Declare bulk/static segments before `{param}` routes so `bulk-archive` is not parsed as an id.
