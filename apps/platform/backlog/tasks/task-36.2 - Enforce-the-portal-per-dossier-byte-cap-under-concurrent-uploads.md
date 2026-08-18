---
id: TASK-36.2
title: Enforce the portal per-dossier byte cap under concurrent uploads
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:34'
updated_date: '2026-08-14 14:44'
labels:
  - audit
dependencies: []
references:
  - app/ClientPortal/Http/Requests/StorePortalUploadedDocumentRequest.php
  - app/ClientPortal/Actions/SubmitPortalUploadAction.php
  - app/Dossiers/Actions/DocumentRequests/UploadDocumentForRequestAction.php
  - app/Dossiers/Support/DocumentUploadLimits.php
  - app/Dossiers/Support/DossierLocks.php
modified_files:
  - app/Dossiers/Support/DocumentUploadLimits.php
  - app/ClientPortal/Http/Requests/StorePortalUploadedDocumentRequest.php
  - app/ClientPortal/Actions/SubmitPortalUploadAction.php
  - tests/Feature/Portal/PortalUploadQuotaTest.php
parent_task_id: TASK-36
priority: high
type: bug
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
StorePortalUploadedDocumentRequest sums size_bytes during validation, outside any transaction or lock; the writes happen later in UploadDocumentForRequestAction. MAX_PORTAL_BYTES_PER_DOSSIER is enforced nowhere else. At throttle 30/min × 10 concurrent × 20 MB, concurrent POSTs can all read the same pre-write sum and bypass the cap — the exact abuse the cap exists to stop, plus an OCR run per file.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Concurrent portal uploads cannot collectively exceed MAX_PORTAL_BYTES_PER_DOSSIER
- [x] #2 The cap is enforced at write time, not only as a pre-write validation sum
- [x] #3 A test covers concurrent or overlapping uploads that would otherwise overshoot the cap
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Move the byte sum out of StorePortalUploadedDocumentRequest into DocumentUploadLimits::portalBytesUsedForDossier(), keeping the getQuery()/toBase() tenant-scope idiom verbatim. Add portalCapMessage() so one `__()` literal serves both enforcement points.
2. Keep the FormRequest sum as a cheap pre-check (friendly validation error), now calling the shared helper.
3. Add the authoritative write-time gate as the FIRST statement of the $afterPersist callback in SubmitPortalUploadAction. That callback already runs inside the transaction holding the dossier lockForUpdate (DossierLocks::documentRequestWithDossier), so no new lock is needed and the sum already includes the just-written row. Throwing there rolls back the row and runs before startPipeline, so an over-cap file never costs an OCR run.
4. Placement rationale: the cap is portal-only; ClientPortal (L5) may import Dossiers (L4) Support. Keeps a portal rule out of the action shared with staff uploads.
5. Tests in tests/Feature/Portal/PortalUploadQuotaTest.php: (a) stale pre-check rejected at write time when driving SubmitPortalUploadAction directly, asserting no UploadedDocument row and no pipeline; (b) dossier lock SELECT emitted, 'for update' asserted only under pgsql (LastOwnerMutationLockTest pattern, since the suite runs SQLite); (c) covers().
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented.

Shared helper: DocumentUploadLimits::portalBytesUsedForDossier() + portalCapMessage(). The getQuery()/toBase() idiom moved verbatim so TenantScope still applies to both the outer sum and the document-request subquery. Precedent for a query method on a Support class: WorkspaceMemberRoles::activeOwnerCount().

Enforcement is now two-tier, matching GenerateDossierExportAction's canStart() pre-check/re-check split:
- StorePortalUploadedDocumentRequest keeps the pre-write sum for a friendly validation error (explicitly commented as non-authoritative).
- SubmitPortalUploadAction's $afterPersist callback is the authoritative gate. NO new lock was needed: that callback already runs inside the transaction holding the dossier lockForUpdate (DossierLocks::documentRequestWithDossier), which serialises every portal upload on the dossier. It runs after the row is written (so the sum includes the incoming file, and a single-file replacement counts once) and before startPipeline (so an over-cap file never costs an OCR run).

Placement: ClientPortal (L5) importing Dossiers Support (L4) is legal and keeps a portal-only rule out of the action shared with staff uploads.

Tests (tests/Feature/Portal/PortalUploadQuotaTest.php, now covers(SubmitPortalUploadAction, DocumentUploadLimits)):
- 'the write-time cap rejects an upload whose pre-write sum went stale' drives the Action directly (what a concurrent POST looks like once its pre-check is invalidated). Asserts the exception MESSAGE not just the class, no UploadedDocument row, Pipeline count 0, and an empty storage directory (the compensating blob delete ran).
- 'the write-time cap is summed inside the transaction holding the dossier lock' uses DB::listen(QueryExecuted) to assert the dossiers read is emitted BEFORE the sum, with 'for update' asserted only under pgsql — the LastOwnerMutationLockTest pattern, because the suite runs SQLite :memory: where lockForUpdate() compiles to nothing.

Non-vacuity proven: with the guard removed, both new tests fail ('not thrown' and 'cap was never summed'); the two pre-existing FormRequest tests still pass, confirming they never covered the race.

Verified: pest 4/4 (18 assertions SQLite); pgsql run on a scratch database laravel_locktest gives 19 assertions, the extra one being the 'for update' assertion actually executing; PHPStan level 8 clean; Rector clean; Pint clean; tests/Unit/DossierLockOrderTest.php + tests/Feature/Dossiers/DossierLockOrderTest.php + tests/Architecture + MultiFileUploadTest all pass (70 tests) so the sole-lock-owner invariant is intact.

Limitation recorded honestly: a true two-connection concurrency test is not possible in this suite (SQLite). What is proven is that the cap is evaluated inside the correct lock scope, and that the lock is a real FOR UPDATE under Postgres.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Moved the portal byte cap from a pre-write validation sum to a write-time gate.

DocumentUploadLimits now owns one definition of bytes-used (portalBytesUsedForDossier) and one cap message. StorePortalUploadedDocumentRequest keeps its sum as a cheap pre-check for a friendly error; SubmitPortalUploadAction's $afterPersist callback is the authoritative gate, evaluated inside the transaction that already holds the dossier lockForUpdate via DossierLocks — which serialises every portal upload on the dossier, so no new lock was introduced and the sole-lock-owner invariant is untouched. It sums after the row is written and before startPipeline, so an over-cap upload rolls back and never charges an OCR run.

Verified: 4/4 in tests/Feature/Portal/PortalUploadQuotaTest.php; the two new tests were proven non-vacuous by removing the guard (both fail, while the pre-existing FormRequest tests still pass — confirming they never covered the race); a Postgres run on a scratch database reports 19 assertions vs 18 on SQLite, the extra one being the 'for update' assertion actually executing. PHPStan level 8, Rector, and Pint clean; 70 tests across the lock-order guards, architecture suite, portal suite, and MultiFileUploadTest pass.

Caveat: a true two-connection concurrency test is impossible in this suite (SQLite :memory: compiles lockForUpdate to nothing). What is proven is that the cap is evaluated inside the correct lock scope and that the lock is a real FOR UPDATE under Postgres.
<!-- SECTION:FINAL_SUMMARY:END -->
