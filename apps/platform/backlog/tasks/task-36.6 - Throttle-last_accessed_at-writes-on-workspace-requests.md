---
id: TASK-36.6
title: Throttle last_accessed_at writes on workspace requests
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:34'
updated_date: '2026-08-14 22:22'
labels:
  - audit
dependencies: []
references:
  - app/Tenancy/Http/Middleware/EnsureValidTenantMembership.php
  - app/Reporting/Support/WorkspaceDashboardCache.php
modified_files:
  - app/Tenancy/Models/TenantMembership.php
  - app/Tenancy/Http/Middleware/EnsureValidTenantMembership.php
  - tests/Feature/Tenancy/WorkspaceAccessThrottleTest.php
parent_task_id: TASK-36
priority: medium
type: enhancement
ordinal: 6000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
EnsureValidTenantMembership writes last_accessed_at on every workspace request — every page, Inertia partial, and deferred-prop XHR. Throttling to about five minutes is a small change; last_accessed_at is already a datetime cast.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 last_accessed_at is not written on every workspace page, Inertia partial, and deferred-prop XHR
- [x] #2 Stale last-access older than a short window (about five minutes) is still updated
- [x] #3 Existing datetime cast on last_accessed_at is reused; no new column
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented without a cache.

The mapping suggested modelling this on WorkspaceDashboardCache (Cache::add as a throttle gate), but comparing the already-loaded last_accessed_at against now()->subSeconds(300) is strictly better here: the middleware already calls loadMissing('tenantMemberships.tenant'), so the check costs NO query at all — not even a Redis round-trip, which a cache gate would have added to every workspace request in exchange for removing a Postgres write. It is also self-correcting (no cache/DB divergence), needs no tenant-prefixed key (PrefixCacheTask is disabled in config/multitenancy.php), and carries no Octane concern.

TenantMembership::ACCESS_RECORD_THROTTLE_SECONDS = 300 and hasRecentAccessRecorded() live on the model, following the DocumentRequest::isOrderedBefore() precedent of a plain named method holding one definition of a derived rule. The existing datetime cast is reused; no new column (AC#3). The middleware keeps its shape and only gains the guard.

Boundary chosen deliberately: exactly at the window counts as stale, so the value can never sit older than five minutes without being refreshed.

Tests in tests/Feature/Tenancy/WorkspaceAccessThrottleTest.php, covers(EnsureValidTenantMembership, TenantMembership): repeated requests inside the window issue zero tenant_memberships UPDATEs; a request past the window issues exactly one and advances the timestamp; a never-accessed membership is written immediately; and hasRecentAccessRecorded() is unit-checked across null / fresh / just-inside / exactly-at / past the boundary.

A vacuous test was caught here and is worth recording: the first version fired three requests back to back and asserted zero UPDATEs — which passed WITHOUT the throttle too, because three requests in the same second produce an identical second-precision timestamp, leaving the model not dirty so Eloquent issues no UPDATE regardless. Fixed by travelling 60 seconds between requests (still inside the 300s window). Re-verified: with the throttle removed the test now fails with 'Failed asserting that 3 is identical to 0'.

Verified: 131 tests pass across tests/Feature/Tenancy and tests/Architecture; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
last_accessed_at is now written at most once every five minutes instead of on every workspace request.

TenantMembership::hasRecentAccessRecorded() compares the already-loaded attribute against the throttle window, so the check costs no query — a cache gate would have traded a Postgres write for a Redis read on every request, which the middleware does not need. The existing datetime cast is reused and no column was added.

Verified with four tests, and the discriminating one was confirmed to fail without the throttle ('3 is identical to 0'). That test was initially vacuous — three back-to-back requests share a second-precision timestamp, so Eloquent issues no UPDATE even unthrottled — and now travels 60 seconds between requests inside the window. 131 tests pass across Tenancy and Architecture; PHPStan level 8, Rector and Pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
