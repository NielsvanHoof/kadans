---
paths:
  - 'app/Tenancy/**'
---

# Tenancy

## Octane-safe state: use Laravel Context, never statics
The app runs on Octane (FrankenPHP) — workers boot once and serve many requests. Request-scoped state in a singleton or static property leaks into the next request.

`TenantContext` is the reference implementation: the strict-scoping flag lives in Laravel's `Context` (`Context::addHidden` / `forgetHidden`), not a static, precisely so it cannot survive into the next request on a reused worker. Follow that pattern for anything request-scoped.

`TenantContext::runForTenant()` is the single entry point for console and async code that must run as a tenant. HTTP sets the tenant via middleware instead.

Prefer `$this->app->scoped()` over `singleton()` for per-request services.
