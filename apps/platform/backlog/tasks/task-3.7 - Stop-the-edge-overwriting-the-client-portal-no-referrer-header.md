---
id: TASK-3.7
title: Stop the edge overwriting the client portal no-referrer header
status: Done
assignee:
  - cursor
created_date: '2026-08-06 09:50'
updated_date: '2026-08-06 11:09'
labels: []
dependencies: []
references:
  - docker/production/Caddyfile
  - app/ClientPortal/Http/Middleware/HardenClientPortalResponse.php
  - app/Shared/Http/Middleware/AddSecurityHeaders.php
  - tests/Feature/Security/ProductionCaddyfileTest.php
  - config/security.php
documentation:
  - docs/security.md
modified_files:
  - docker/production/Caddyfile
  - docs/security.md
  - config/security.php
  - app/ClientPortal/Http/Middleware/HardenClientPortalResponse.php
  - tests/Feature/Security/ProductionCaddyfileTest.php
parent_task_id: TASK-3
priority: medium
type: bug
ordinal: 15000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The production Caddyfile sets Referrer-Policy with the bare header directive, which replaces the field rather than filling it when absent. The portal hardening middleware sets no-referrer, and docs/security.md claims it survives. That is true of the general security headers middleware, which only fills absent headers, but not of the edge.

So in production portal pages downgrade to strict-origin-when-cross-origin, and the application origin leaks on outbound navigations from a page holding a client dossier. Data value is low because the token URL is a redirect that never renders, but it is a documented control that is inert exactly where it matters.

The existing portal tests assert no-referrer at the Laravel layer only, which is why this went unnoticed. The new assertion has to run against the edge configuration, not the framework response.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Portal responses carry Referrer-Policy no-referrer when served through the production edge
- [x] #2 Non-portal responses still carry the stricter default policy
- [x] #3 The header ownership is in one place rather than split between Caddy and PHP, and the choice is documented
- [x] #4 A test asserts the edge configuration does not replace headers the application sets, so the regression cannot silently return
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Remove Referrer-Policy from docker/production/Caddyfile header block (keep X-Content-Type-Options nosniff and -Server). PHP already owns the policy: AddSecurityHeaders fills strict-origin-when-cross-origin when absent; HardenClientPortalResponse sets no-referrer for portal routes.
2. Update docs/security.md Response hardening section: document that Referrer-Policy is application-owned only; Caddy must not set/replace it. Fix the claim that portal no-referrer "survives" the edge.
3. Add a feature test that reads docker/production/Caddyfile and asserts it does not contain a bare Referrer-Policy directive (regression guard for AC #4). Existing ClientPortalTest + SecurityHeadersTest already cover AC #1/#2 at the Laravel layer; with the edge no longer replacing, those values reach the client.
4. Leave docker/frankenphp/Caddyfile alone (local; no security header block).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Chose full removal of Referrer-Policy from Caddy over `?Referrer-Policy` fill-if-absent: AC #3 asks for ownership in one place; PHP already differentiates portal vs non-portal.

Verification: vendor/bin/sail artisan test --compact tests/Feature/Security/ProductionCaddyfileTest.php tests/Feature/Security/SecurityHeadersTest.php tests/Feature/Portal/ClientPortalTest.php -> 35/35 passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Removed Referrer-Policy from docker/production/Caddyfile so the edge no longer replaces the application value. Caddy's bare `header` directive overwrote HardenClientPortalResponse's `no-referrer` with `strict-origin-when-cross-origin` on every production portal response; PHP now owns Referrer-Policy exclusively (AddSecurityHeaders baseline + portal hardening). Caddy still sets nosniff and strips Server.

Documented ownership in docs/security.md, config/security.php, and HardenClientPortalResponse. Added ProductionCaddyfileTest asserting the Caddyfile never sets Referrer-Policy (including the deferred `?` form).

Verification: vendor/bin/sail artisan test --compact tests/Feature/Security/ProductionCaddyfileTest.php tests/Feature/Security/SecurityHeadersTest.php tests/Feature/Portal/ClientPortalTest.php → 35/35 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
