---
id: TASK-36.1
title: Prevent dossier and document-request lock-order deadlocks
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:34'
updated_date: '2026-08-14 11:02'
labels:
  - audit
dependencies: []
references:
  - app/Dossiers/Actions/CompleteDossierAction.php
  - app/ClientPortal/Actions/SubmitPortalAnswerAction.php
  - app/Dossiers/Actions/SubmitStaffQuestionnaireAnswerAction.php
  - app/DocumentRequests/Actions/UploadDocumentForRequestAction.php
parent_task_id: TASK-36
priority: high
type: bug
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
CompleteDossierAction and the builder actions lock dossier then document_request. SubmitPortalAnswerAction, SubmitStaffQuestionnaireAnswerAction, and UploadDocumentForRequestAction lock document_request then dossier. Staff completing a dossier while a client submits the last answer can deadlock (SQLSTATE 40P01) and 500 for whoever loses. No DB::transaction() call in these paths passes attempts, so nothing retries.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Staff completing a dossier while a client submits the last answer does not produce SQLSTATE 40P01
- [x] #2 All dossier and document-request mutations in the same transaction use one canonical lock order, or deadlock victims retry
- [x] #3 A test or documented lock-order convention makes the canonical order enforceable
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Canonical order is dossiers -> document_requests -> client_access_grants (parent before child; the majority of sites and PipelineState's Pipeline->PipelineStep precedent already read that way). Fix by centralizing the locks, not by adding DB::transaction retry attempts - the upload closures dispatch pipelines and delete files, so re-running them on a deadlock retry is worse than preventing the cycle.

Audited every lockForUpdate site in app/. Violators (document_request before dossier) are FOUR, not the three the task lists, because two hide the dossier lock inside UploadDocumentForRequestAction's $afterPersist callback:
1. SubmitPortalAnswerAction: DR -> Dossier -> Grant
2. SubmitStaffQuestionnaireAnswerAction: DR -> Dossier
3. UploadStaffDocumentAction: DR -> Dossier (via callback)
4. SubmitPortalUploadAction: DR -> Dossier -> Grant (via callback)
Dossier-first side: CompleteDossierAction + 6 Builder actions. DocumentRequestTransitions only READS the dossier (no $touches, no dossier write), so it is not a lock participant.

Steps:
1. Add app/Dossiers/Support/DossierLocks.php - the single writer of dossier/document-request row locks (Support, not Actions: many operations, matching PipelineState). Methods: dossier(), documentRequestWithDossier() returning array{Dossier, DocumentRequest}, documentRequestsFor(Dossier). Grant locks stay in ClientPortal (Dossiers is L4 and must not import L5) and are documented as always last.
2. UploadDocumentForRequestAction: lock the dossier before the document request and pass the locked Dossier into $afterPersist, so the two upload callbacks stop locking it themselves. Callback signature becomes (UploadedDocument, DocumentRequest, Dossier).
3. Route SubmitPortalAnswerAction, SubmitStaffQuestionnaireAnswerAction, CompleteDossierAction, and the Builder actions through DossierLocks.
4. tests/Unit/DossierLockOrderTest.php - source-scanning contract test in the ControllerActionsTest genre (pure PHP, no DB): (A) the sequence of locked models per file is non-decreasing in canonical rank, so a grant lock can never precede a dossier lock; (B) no class outside DossierLocks takes a dossiers/document_requests lock, which is what closes the callback-indirection hole.
5. Feature test asserting emitted query order: SQLite drops FOR UPDATE from the SQL, but the locking SELECT is still a real query, so DB::listen proves dossiers is read before document_requests in both the complete and the submit paths.
6. Reproduce the actual 40P01 on the dev Postgres with two concurrent connections before and after, as AC #1 evidence (not added to the suite - the suite is SQLite).
7. Record the canonical order with Boost record-rule.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented as planned. app/Dossiers/Support/DossierLocks.php is now the only class that takes a dossiers or document_requests row lock; 18 call sites route through it.

Scope correction worth recording: the task listed three violators and two stale paths. The real count is four, because UploadStaffDocumentAction and SubmitPortalUploadAction never locked the dossier in their own file - they locked it inside the $afterPersist closure that UploadDocumentForRequestAction invokes AFTER taking the item lock. Both files read as dossier-first in isolation, which is why a comment-only convention would not have held. UploadDocumentForRequestAction now locks the dossier first and passes it into the callback. Also fixed grant ordering: SubmitPortalAnswerAction locked grant-after-dossier while SubmitPortalUploadAction locked it before the item, a second latent pair; both now lock the grant last.

Not done, deliberately: no DB::transaction($cb, $attempts) retries. The upload closures dispatch pipelines and delete files, so replaying one on a deadlock is worse than never forming the cycle. AC #2's first branch (one canonical order) is satisfied instead.

Verification evidence:
1. AC #1 premise on the real dev Postgres, two concurrent sessions via the pgsql async API (pg_send_query; forking would share the socket): reversed order -> 'SQLSTATE 40P01 deadlock detected'; canonical order -> the second session simply blocks on the dossier row and completes after the first commits. Ran on throwaway tables so no application row was created or touched - a lock-order cycle is a property of the order, not the table. Not added to the suite: the suite is single-connection SQLite.
2. AC #1/#2 in-app: tests/Feature/Dossiers/DossierLockOrderTest.php uses DB::listen to assert the emitted query order. SQLite compiles lockForUpdate() to nothing so there is no 'for update' text to match, but the locking SELECT is still a real query and its position IS the acquisition order. Falsified by reversing DossierLocks::documentRequestWithDossier(): the staff-answer AND the staff-upload (callback) tests both fail, then pass on restore. This is the only check that sees through the callback.
3. AC #3: tests/Unit/DossierLockOrderTest.php, source scan in the ControllerActionsTest genre. (A) ranked locks per file are non-decreasing in canonical order; (B) only DossierLocks locks those two tables. Falsified against BOTH revert styles - imported and fully-qualified \App\Dossiers\Models\Dossier - and both assertions fire. The first draft passed on the FQCN revert because the scanner silently matched nothing, so the file also carries a sentinel test proving the scanner finds what it polices.
4. Suite: vendor/bin/sail artisan test --compact -> 1137 passed, 6482 assertions. PHPStan level 8: 0 errors. Pint: passed. Rector: clean (it asked for final readonly on ReorderDocumentRequestsAction, which gained its first constructor; applied).

Observation left alone as out of scope: DocumentRequestTransitions::ensureCanBeSubmitted() does not forward the dossier to canSubmit(), so the Portal branch lazy-loads $documentRequest->dossier even when the caller already holds it locked. Harmless (an unlocked read of a row we hold, same transaction) but a wasted query; threading it through would mean changing submitAnswer/submitUpload signatures.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Established one canonical row-lock order (dossiers -> document_requests -> client_access_grants) and centralized it in the new app/Dossiers/Support/DossierLocks.php, now the only class that locks either table across 18 call sites. Four paths locked the pair the wrong way round, not the three listed: UploadStaffDocumentAction and SubmitPortalUploadAction hid their dossier lock in UploadDocumentForRequestAction's $afterPersist callback, so both files read as dossier-first on their own; that action now locks the dossier first and hands it to the callback. Also aligned the portal grant lock, which two portal actions took on opposite sides of the item lock. No transaction retries by design - the upload closures dispatch pipelines and delete files.

Verified by: reproducing SQLSTATE 40P01 on the dev Postgres with two concurrent sessions and confirming the canonical order only blocks instead of deadlocking (throwaway tables, no app data); a Feature test that asserts the emitted query order via DB::listen, falsified by reversing the helper so both the answer and the callback-hidden upload path fail; and a source-scanning Unit test enforcing canonical order plus centralization, falsified against both imported and fully-qualified revert styles. Full suite 1137 passed / 6482 assertions, PHPStan level 8 clean, Pint and Rector clean. Convention recorded in .ai/rules/dossiers-client-portal.md.
<!-- SECTION:FINAL_SUMMARY:END -->
