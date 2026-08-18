---
id: TASK-15.2
title: Align the upload size limits and tighten two small input surfaces
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:38'
labels: []
dependencies: []
references:
  - docker/production/php.ini
  - app/ClientPortal/Http/Requests/StorePortalUploadedDocumentRequest.php
  - app/Shared/Http/Middleware/HandleAppearance.php
  - app/Platform/Http/SharedProps/WorkspaceProps.php
parent_task_id: TASK-15
priority: low
type: bug
ordinal: 56000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Three unrelated small items:

The PHP post size limit is 25MB while the validation rules allow ten files of 20MB each. A multi-file submission over the limit arrives with an empty body, so the user gets a token-mismatch error or a misleading message about a missing document file. It will read as data loss to a client mid-upload.

The appearance cookie is excluded from encryption, so it is fully attacker-writable, and it is interpolated into an inline script tag. It is not exploitable - Blade escaping converts the quote and angle bracket characters, and cookies cannot carry raw newlines - but a value ending in a backslash produces a JavaScript syntax error that breaks the dark-mode bootstrap. It should be allowlisted to the three valid values rather than relying on one escaping detail.

The portal receives the firm slug through shared Inertia props because the portal middleware makes the tenant current. The firm name is shown deliberately, but the slug is the workspace URL segment, is not needed on the portal, and feeds slug enumeration for the Reverb channel issue.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The PHP post size limit and the upload validation rules agree, and an oversized submission produces a clear error naming the size limit
- [x] #2 The appearance cookie value is allowlisted to its three valid values
- [x] #3 The portal no longer receives the firm slug in shared props
- [x] #4 Tests cover the oversized upload message and the invalid appearance value
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Align docker/production/php.ini post_max_size (and related) with max upload validation (10×20MB or raise PHP / lower validation — prefer coherent clear error).
2. Handle oversized POST with clear size-limit message (StorePortalUploadedDocumentRequest / middleware).
3. Allowlist appearance cookie to light|dark|system in HandleAppearance.
4. Stop sharing firm slug on portal via WorkspaceProps.
5. Tests for oversized upload message + invalid appearance.

Exception handler starts/saves session from cookie before withErrors so oversized POSTs still flash documents errors.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Session flash for PostTooLargeException required manually starting/saving the session because ValidatePostSize runs before StartSession. Tests visit a page first so the session cookie exists, then POST with CONTENT_LENGTH over post_max_size.

Sail CLI still reports post_max_size=100M; HTTP Octane uses Caddyfile php_ini (20M/210M). Restart Sail/Octane to pick up Caddyfile changes.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
- Aligned staff/portal upload validation and frontend dropzone defaults with DocumentUploadLimits (10 × 20 MB) and production PHP/Caddy post_max_size=210M.
- PostTooLargeException now flashes a clear documents error naming the 200 MB ceiling; session is rebound from the cookie so the flash survives ValidatePostSize running before StartSession.
- Appearance cookie allowlisted to light|dark|system; portal shared props expose firm name only (no slug).

## Tests
- `vendor/bin/sail artisan test --compact tests/Feature/Dossiers/DocumentUploadPostTooLargeTest.php tests/Feature/Shared/AppearanceCookieTest.php tests/Feature/Portal/PortalSharedPropsTest.php` — 6 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
