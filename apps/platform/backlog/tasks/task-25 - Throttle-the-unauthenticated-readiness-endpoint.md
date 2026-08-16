---
id: TASK-25
title: Throttle the unauthenticated readiness endpoint
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-08 13:35'
updated_date: '2026-08-11 08:41'
labels: []
milestone: m-0
dependencies: []
references:
  - routes/health.php
  - app/Platform/Http/Controllers/ReadinessController.php
documentation:
  - docs/security.md
modified_files:
  - routes/health.php
  - tests/Feature/Platform/ReadinessEndpointTest.php
  - docs/security.md
priority: medium
type: bug
ordinal: 78000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`GET /ready` is registered with no middleware at all — no `web`, no auth, no throttle — and every hit runs the real readiness checks behind `ChecksReadiness`, which touch the database and Redis. An unauthenticated caller can therefore drive backend work at request rate from a single cheap GET.

The neighbouring `health` diagnostics route was correctly gated behind `auth` plus `EnsurePlatformSupport` by TASK-3.5. `ready` cannot take the same treatment because a load balancer has to call it anonymously, so the fix is a rate limit rather than a gate. The limit needs to sit above the balancer poll interval so a normal health check never trips it.

Worth confirming while in the file whether the failure response leaks anything beyond `{"status":"unavailable"}` — the controller currently reports the exception to Sentry and returns a bare status, which is the right shape.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Repeated anonymous requests to /ready are rate limited, and the limit is documented as comfortably above the load balancer poll interval
- [x] #2 A single request within the limit still returns 200 with the ready status, and an unhealthy check still returns 503
- [x] #3 A test covers the throttled response
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Approach
Anonymous `/ready` cannot use auth (LB probes). Follow project convention: inline `throttle:N,1` on the route (same pattern as portal/feature routes).

## Limit choice
`throttle:60,1` (60/min per IP). Typical ALB intervals are 5–30s (≤12/min from one checker). 60 leaves headroom for multiple targets/NAT without being unlimited. Document the rationale in the route comment and briefly in `docs/security.md`.

## Changes
1. `routes/health.php` — attach `throttle:60,1` to `ready` with a short comment about LB poll headroom. Confirm failure body stays `{"status":"unavailable"}` (already true; no controller change).
2. `tests/Feature/Platform/ReadinessEndpointTest.php` — add a rate-limit case using a dedicated `REMOTE_ADDR` so prior `/ready` hits in the file do not share the bucket; burn 60 OK then assert 429.
3. `docs/security.md` — note anonymous `/ready` is throttled (60/min) while `/health` stays support-gated.

## Verify
Run `ReadinessEndpointTest` (ready/unhealthy/throttle cases).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Chose inline throttle:60,1 per routes.md convention (named RateLimiters reserved for Fortify). Confirmed failure JSON stays bare unavailable. ReadinessEndpointTest fully green.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Throttled anonymous `GET /ready` so unauthenticated callers cannot drive DB/Redis readiness checks at request rate.

- `routes/health.php`: `throttle:60,1` on `ready`, with a comment that 60/min sits above typical ALB poll intervals (5–30s).
- Left `ReadinessController` unchanged — failures still report to Sentry and return only `{"status":"unavailable"}`.
- Documented the limit in `docs/security.md` alongside the note that `/health` remains support-gated.

## Tests
`tests/Feature/Platform/ReadinessEndpointTest.php` — 13 passed, including:
- ready → 200 `{"status":"ready"}`
- unhealthy → 503 `{"status":"unavailable"}`
- new `readiness is rate limited for anonymous callers` (dedicated `REMOTE_ADDR`, 60 OK then 429)
<!-- SECTION:FINAL_SUMMARY:END -->
