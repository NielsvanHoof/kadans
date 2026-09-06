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

## tenant_id writes must match the current tenant
`BelongsToTenant` refuses creates whose `tenant_id` differs from `Tenant::current()`, and rejects dirty `tenant_id` on update. Keep `tenant_id` in `$fillable` for factories. Cross-tenant fixtures must switch context first (`actingInWorkspace` / `runForTenant`), not smuggle another id. Root factories that default `tenant_id` (`ClientFactory`, `TenantInvitationFactory`) prefer `Tenant::current()?->getKey() ?? Tenant::factory()` so nested Pipeline→…→Client graphs stay in-tenant.

## Last-owner mutations lock the tenant row first
Remove, suspend, and demote must call WorkspaceMemberRoles::lockOwnerMutations($tenantId) inside the transaction before locking the target membership. A membership-row lock does not serialize two owner mutations on different rows (READ COMMITTED both count 2). Do not use pg_advisory_xact_lock: tests run SQLite, which neither supports advisory locks nor emits FOR UPDATE. Leave isLastActiveOwner() unlocked — Policy and FormRequest call it outside a transaction.

## Staff-options cache, no membership observers
Staff dropdowns go through FetchResponsibleStaffOptionsQuery + StaffOptionsCache (Cache::flexible, tenant id in the key, 500 cap). Forget from Invite/Accept/Remove/Suspend/UpdateRole/Provision after the transaction commits — never from TenantMembership model events, because last_accessed_at writes every 5 minutes and would thrash the cache. Do not route this through TenantCacheInvalidator (Reporting-owned). The members page payload stays uncached (viewer-specific) but is LIMITed to 500; do not paginate that UI.

## Mutating the tenant row needs an explicit rebind — makeCurrent() short-circuits
The multitenancy package binds the current tenant with `app()->instance('currentTenant', …)`, and `ImplementsTenant::makeCurrent()` returns early whenever `isCurrent()` matches — and `isCurrent()` compares only the primary key.

On Octane the container outlives the request, so after a write to the `tenants` row the worker keeps serving the *previous* attribute values from the object it bound earlier: `InitializeTenantFromRoute` loads a fresh record every request, calls `makeCurrent()`, and that call does nothing. It self-corrects only when the worker happens to serve a different firm.

`RenameTenantAction` is the only writer today and handles it: `Tenant::forgetCurrent()` then `makeCurrent()`. Any future action that updates a tenant attribute must do the same, or `Tenant::current()->name` goes stale for the rest of the worker's life.

A test that asserts the outcome on the *next* request catches this; one that asserts the write or the flash flag does not.
