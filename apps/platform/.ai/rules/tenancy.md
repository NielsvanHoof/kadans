---
paths:
  - 'app/Tenancy/**'
---

# Tenancy

## Octane-safe state: use Laravel Context, never statics
The app runs on Octane (FrankenPHP) — workers boot once and serve many requests. Request-scoped state in a singleton or static property leaks into the next request.

`TenantContext` is the reference implementation: the strict-scoping flag lives in Laravel's `Context` (`Context::addHidden` / `forgetHidden`), not a static, precisely so it cannot survive into the next request on a reused worker. Follow that pattern for anything request-scoped.

`TenantContext::runForTenant()` is the single entry point for console and async code that must run as a tenant. HTTP sets the tenant via middleware instead (`InitializeTenantFromRoute` on `{slug}.{base}`, `InitializeTenantFromSession` on the central app host).

Tenant slugs are DNS labels on `{slug}.{base_domain}` (see `config/tenancy-hosts.php`). Reserved first labels (`app`, `www`, `mail`, `api`, …) must never become firm slugs — enforce via `TenantHostname` at provision time and in host middleware.

Prefer `$this->app->scoped()` over `singleton()` for per-request services.

## tenant_id writes must match the current tenant
`BelongsToTenant` refuses creates whose `tenant_id` differs from `Tenant::current()`, and rejects dirty `tenant_id` on update. Keep `tenant_id` in `$fillable` for factories. Cross-tenant fixtures must switch context first (`actingInWorkspace` / `runForTenant`), not smuggle another id. Root factories that default `tenant_id` (`ClientFactory`, `TenantInvitationFactory`) prefer `Tenant::current()?->getKey() ?? Tenant::factory()` so nested Pipeline→…→Client graphs stay in-tenant.
