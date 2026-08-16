---
id: TASK-15.3
title: Bound portal upload volume per grant
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:43'
labels: []
dependencies: []
references:
  - routes/portal.php
  - app/ClientPortal/Http/Requests/StorePortalUploadedDocumentRequest.php
documentation:
  - docs/security.md
parent_task_id: TASK-15
priority: low
type: enhancement
ordinal: 57000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
A holder of a valid portal grant - valid for up to ninety days, re-exchangeable, with no device or IP binding, which is a documented and accepted tradeoff - can post thirty requests a minute, each carrying up to ten files. There is no per-dossier or per-grant upload quota, no total-bytes cap and no malware scanning.

That is a large volume of attacker-chosen bytes per minute written to the production documents bucket, plus a provider job per file: direct billing denial of service, and a staging area for files staff are trained to open. Spoofed forwarding headers remove the per-minute ceiling entirely, so this should land after trusted proxies are pinned.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A per-grant or per-dossier upload quota exists and is enforced
- [x] #2 Exceeding it produces a clear message to the client rather than a generic error
- [x] #3 A test covers the quota boundary
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Confirm TrustedProxiesTest / production.trusted_proxies already pin proxies (prerequisite met).
2. Add per-dossier portal upload byte ceiling on DocumentUploadLimits (default 500 MB) + human label.
3. Enforce in StorePortalUploadedDocumentRequest::withValidator: sum existing UploadedDocument.size_bytes for the grant's dossier + incoming files; fail documents with a clear message.
4. Document the bound in docs/security.md (portal / uploads section).
5. Feature test: under limit succeeds; over limit returns clear documents error and stores nothing.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Chose per-dossier (not per-grant) because grants are re-issuable and would reset a grant-scoped cap.

Fixed ProcessUploadedDocumentJob uniqueFor to use TIMEOUT_SECONDS — #[Timeout] does not populate $timeout, which broke ShouldBeUnique dispatch on uploads.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
- Enforced a per-dossier portal upload ceiling of 500 MB (`DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER`) in `StorePortalUploadedDocumentRequest`: existing `UploadedDocument.size_bytes` for the grant's dossier plus incoming files.
- Over-quota uploads get a clear `documents` validation error; nothing is stored.
- Documented in `docs/security.md`. Trusted proxies prerequisite already covered by `TrustedProxiesTest`.
- Unblocked upload dispatch: `ProcessUploadedDocumentJob::uniqueFor()` now uses `TIMEOUT_SECONDS` (attribute-based timeout no longer exposes `$timeout`).

## Tests
- `vendor/bin/sail artisan test --compact tests/Feature/Portal/PortalUploadQuotaTest.php` — 2 passed
- Also reconfirmed `guest can upload a document through the portal`.
<!-- SECTION:FINAL_SUMMARY:END -->
