---
id: decision-6
title: Accept incomplete support-session expiry audit for v1.0
date: '2026-08-11 10:05'
status: superseded
---
## Context

`EnsureValidTenantMembership` called `clearIfExpired()`, but
`InitializeTenantFromRoute` returned 404 on `canAccessTenant()` first. For a support
user with no membership in the firm, expiry never fired —
`AuditEvent::SupportSessionExpired` was only reachable when the support user was
also a member. The trail could show a session starting and never ending.

## Decision

~~Accept this audit-trail incompleteness for v1.0.~~ **Superseded by TASK-33.**

Expiry is now cleared and audited in `InitializeTenantFromRoute` (and
`InitializeTenantFromSession`) *before* `canAccessTenant()`, so non-member support
users still get a 404 while the trail records `SupportSessionExpired`.

## Consequences

- Accepted-risk row removed from `docs/security.md`.
- PlatformSupportTest asserts the audit + 404 path for non-members.
