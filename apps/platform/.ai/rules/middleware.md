---
paths:
  - 'app/Tenancy/Http/Middleware/**'
---

# Middleware

## Clear expired support sessions before canAccessTenant
Always call SupportSessionState::clearIfExpired() before User::canAccessTenant() in tenant-init middleware. canAccessTenant → isActiveFor → SupportSession::active() silently forgets expired payloads without auditing; EnsureValidTenantMembership never runs for non-member support users who 404 first.
