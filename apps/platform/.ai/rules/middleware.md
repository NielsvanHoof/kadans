---
paths:
  - 'app/Tenancy/Http/Middleware/**'
---

# Middleware

## Clear expired support sessions before canAccessTenant
Always call SupportSessionState::clearIfExpired() before User::canAccessTenant() in tenant-init middleware. canAccessTenant → isActiveFor → SupportSession::active() silently forgets expired payloads without auditing; EnsureValidTenantMembership never runs for non-member support users who 404 first.

## Cache tenant directory lookups
InitializeTenantFromRoute/Session resolve the tenant row through TenantLookup (Cache::memo()->remember, keys tenant:slug:{slug} and tenant:id:{id}, 300s). Do not query Tenant::query() in that middleware — Inertia deferred props are a second HTTP request and would hit Postgres again. Keep loadMissing('tenantMemberships') live; that is authorization. Forget both keys from Tenant::booted() on update/delete (no Observer). Never once() or a static: Octane flushes once() on OperationTerminated and statics leak across requests.
