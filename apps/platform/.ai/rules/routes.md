---
paths:
  - 'routes/**'
---

# Routes

## Tenant middleware must resolve before SubstituteBindings
Load-bearing ordering: `bootstrap/app.php` prepends `InitializeTenantFromSession`, `InitializeTenantFromRoute`, and `ResolveClientPortalGrant` **before** `SubstituteBindings`, so the tenant is current before route model binding applies `BelongsToTenant`. Breaking this silently unscopes bound models. `tests/Feature/Tenancy/TenantMiddlewarePriorityTest.php` guards it.

Domain route files declare relative paths only; the `workspaces.` prefix and name are applied once in `routes/tenant.php`.

Every route that streams or downloads document bytes carries `RequirePassword::using(passwordTimeoutSeconds: config('auth.document_access_timeout'))`. A retry/status route that streams nothing deliberately does not.

Rate limiting: named `RateLimiter::for()` limiters for Fortify auth flows (registered in `FortifyServiceProvider`); inline `throttle:N,1` on feature and portal routes. Fortify has no config hook for password-reset routes — those are throttled by attaching middleware to the named routes after Fortify registers them.

Declare bulk/static segments before `{param}` routes so `bulk-archive` is not parsed as an id.

## UUID route params need whereUuid
Route params that bind a PostgreSQL uuid column (notifications.id) must use whereUuid(). An unconstrained segment like "foo" becomes SQLSTATE 22P02 — an uncaught QueryException 500 — instead of a 404. Integer id routes use where(..., '[0-9]+') the same way.
