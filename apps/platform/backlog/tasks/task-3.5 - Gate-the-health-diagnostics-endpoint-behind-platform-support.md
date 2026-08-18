---
id: TASK-3.5
title: Gate the health diagnostics endpoint behind platform support
status: Done
assignee:
  - '@claude'
created_date: '2026-08-06 09:50'
updated_date: '2026-08-06 10:59'
labels: []
dependencies: []
references:
  - routes/health.php
  - routes/web/platform.php
modified_files:
  - routes/health.php
  - tests/Feature/Platform/ReadinessEndpointTest.php
parent_task_id: TASK-3
priority: high
type: bug
ordinal: 13000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The /health route carries only the web and auth middleware, so any verified staff member of any firm can read the Spatie Health JSON: database and Redis reachability, disk space, package versions and queue state. That includes a user who just self-registered a firm, since creating a workspace is open to any authenticated user.

The platform support routes are correctly gated with EnsurePlatformSupport, so this endpoint is the outlier rather than the convention.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A verified user who is not platform support receives 403 from the health details endpoint
- [x] #2 A platform support user can still read it
- [x] #3 The load balancer readiness endpoint is unaffected
- [x] #4 A feature test covers both the allowed and denied cases
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add EnsurePlatformSupport to the /health route middleware stack in routes/health.php (alongside existing web + auth), matching the platform support routes in routes/web/platform.php.
2. Leave /ready (ReadinessController) ungated — load balancer probe must stay public.
3. Extend ReadinessEndpointTest: assertForbidden for a normal verified user; assert a platformSupport() user receives the Spatie JSON diagnostics (200 or 503 depending on stored check health — 503 is Spatie's failure status, not an auth denial).
4. Keep the existing unauthenticated assertUnauthorized and readiness/liveness tests as proof /ready is unaffected.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Route change already applied: Route::middleware(['web', 'auth', EnsurePlatformSupport::class]) on health.details.

Platform-support happy-path initially used assertOk(), which failed with 503 — Spatie HealthCheckJsonResultsController returns config health.json_results_failure_status (503) when containsFailingCheck(). Replaced with expect(status)->toBeIn([200, 503]) plus assertJsonStructure(['finishedAt', 'checkResults']) so the test proves authorization without requiring all checks green.

Verification: vendor/bin/sail artisan test --compact tests/Feature/Platform/ReadinessEndpointTest.php -> 12/12 passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Gated the Spatie Health JSON diagnostics endpoint (`/health`, `health.details`) behind `EnsurePlatformSupport` in addition to `web` + `auth`, matching the platform support route convention. Any verified non-support user now gets 403; unauthenticated stays 401; platform support still receives the diagnostics JSON (HTTP 200 when checks are healthy, 503 when Spatie reports failing checks — that status is the payload, not an access denial). The load balancer readiness probe (`/ready`) remains public and is covered by existing tests in the same file.

Tests: added forbidden + platform-support access cases in `ReadinessEndpointTest.php`. `vendor/bin/sail artisan test --compact tests/Feature/Platform/ReadinessEndpointTest.php` → 12/12 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
