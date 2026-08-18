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
