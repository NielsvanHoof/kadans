---
id: TASK-33
title: Audit and clear expired support sessions before tenant access denial
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-11 10:20'
updated_date: '2026-08-11 10:22'
labels:
  - security
  - tenancy
milestone: m-0
dependencies: []
references:
  - app/Tenancy/Http/Middleware/InitializeTenantFromRoute.php
  - app/Tenancy/Http/Middleware/EnsureValidTenantMembership.php
  - app/Platform/Support/PlatformSupportSessionState.php
  - tests/Feature/Platform/PlatformSupportTest.php
  - tests/Feature/Audit/AuditTrailCompletenessTest.php
documentation:
  - docs/security.md
  - >-
    backlog/decisions/decision-6 -
    Accept-incomplete-support-session-expiry-audit-for-v1.0.md
modified_files:
  - app/Tenancy/Http/Middleware/InitializeTenantFromRoute.php
  - app/Tenancy/Http/Middleware/InitializeTenantFromSession.php
  - tests/Feature/Platform/PlatformSupportTest.php
  - tests/Feature/Audit/AuditTrailCompletenessTest.php
  - docs/security.md
  - >-
    backlog/decisions/decision-6 -
    Accept-incomplete-support-session-expiry-audit-for-v1.0.md
priority: medium
type: bug
ordinal: 89000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Expired platform support sessions only get `SupportSessionExpired` audited when `EnsureValidTenantMembership` runs. `InitializeTenantFromRoute` 404s on `canAccessTenant()` first for support users with no firm membership, so the trail can show a session that starts and never ends (decision-6 accepted this for v1.0).

Fix the ordering so expiry is cleared and audited before access is denied, without changing the 404 behaviour for non-members. Remove the accepted-risk framing once proven.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 An expired platform support session for a support user with no firm membership is cleared and audited as SupportSessionExpired when they hit a workspace route
- [x] #2 The request still returns 404 (not leaked as a different status) after expiry for non-members
- [x] #3 Existing member+support expiry audit path still works
- [x] #4 PlatformSupportTest and AuditTrailCompletenessTest no longer document this as an accepted gap
- [x] #5 docs/security.md no longer lists incomplete support-session expiry audit as an accepted risk; decision-6 is superseded
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Inject SupportSessionState into InitializeTenantFromRoute (and InitializeTenantFromSession for the same gap) and call clearIfExpired() before canAccessTenant().
2. Keep EnsureValidTenantMembership clearIfExpired as defense in depth for member+support paths.
3. Update PlatformSupportTest: expect SupportSessionExpired + still assertNotFound.
4. Update AuditTrailCompletenessTest comments; keep member path test; optionally align wording with the non-member path now covered in PlatformSupportTest.
5. Remove decision-6 row from docs/security.md Accepted risks; mark decision-6 superseded.
6. Run PlatformSupportTest + AuditTrailCompletenessTest; pint dirty.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Root cause: canAccessTenant→isActiveFor→active() silently dropped expired payloads before EnsureValidTenantMembership could audit. Fix: clearIfExpired first in both InitializeTenant* middlewares.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Expired platform support sessions are now cleared and audited before tenant access denial, including for support users with no firm membership (closes decision-6).

## Changes
- `InitializeTenantFromRoute` and `InitializeTenantFromSession` inject `SupportSessionState` and call `clearIfExpired()` before `canAccessTenant()`.
- `EnsureValidTenantMembership` keeps its clear as defense in depth (no double-audit once cleared).
- `PlatformSupportTest`: non-member expired session still 404s and now asserts `SupportSessionExpired` + session key gone.
- `AuditTrailCompletenessTest`: comments updated; member path still asserts audit + OK dashboard.
- Removed the accepted-risk row from `docs/security.md`; marked **decision-6** as `superseded`.

## Tests
`vendor/bin/sail artisan test --compact --filter='expired support sessions are rejected|an expired support session is audited'` — 2 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
