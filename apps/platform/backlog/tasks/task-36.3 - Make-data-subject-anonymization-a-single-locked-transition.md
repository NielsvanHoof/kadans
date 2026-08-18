---
id: TASK-36.3
title: Make data-subject anonymization a single locked transition
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:34'
updated_date: '2026-08-14 14:55'
labels:
  - audit
  - gdpr
dependencies: []
references:
  - app/Platform/Actions/DataSubject/AnonymizeDataSubjectAction.php
  - app/Platform/Actions/DataSubject/RefuseDataSubjectRequestAction.php
  - app/Platform/Enums/DataSubjectRequestStatus.php
  - app/Platform/Models/DataSubjectRequest.php
modified_files:
  - app/Platform/Transitions/DataSubjectRequestTransitions.php
  - app/Platform/Actions/DataSubject/AnonymizeDataSubjectAction.php
  - app/Platform/Actions/DataSubject/RefuseDataSubjectRequestAction.php
  - tests/Feature/Platform/DataSubjectRequestTransitionsTest.php
  - tests/Feature/Retention/DataSubjectErasureTest.php
parent_task_id: TASK-36
priority: high
type: bug
ordinal: 3000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
AnonymizeDataSubjectAction is the one guarded transition with no lockForUpdate (dozens exist elsewhere). A double-click re-runs erasure: two DataSubjectErasureCompleted audit entries with different completed_by. deleteExportArchives() also writes sibling rows outside the transaction.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A double-submit of anonymization cannot produce two DataSubjectErasureCompleted audit entries with different completed_by
- [x] #2 The guarded transition takes a lock comparable to the other lockForUpdate transitions in the app
- [x] #3 Sibling export-archive deletes happen inside the same transaction as the status change
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented.

New app/Platform/Transitions/DataSubjectRequestTransitions.php (Platform's first Transitions class; the rules-index glob app/*/{Actions,Queries,Services,Transitions}/** already covers it). Follows the DossierExportTransitions shape: final class, no constructor, canClose()/lockIfOpen()/complete()/refuse(). lockIfOpen() takes the row lock and re-reads status, returning null when someone else closed the request; it deliberately does NOT open the transaction, because the lock must be held for the whole erasure rather than just the status write.

Both Actions restructured to: cheap pre-check (fast fail, no transaction) -> DB::transaction -> lockIfOpen -> throw the same ValidationException if the race was lost -> work on the LOCKED instance -> audit inside the transaction. AnonymizeDataSubjectAction now does everything in one transaction, including clearExportArchiveRows(); the archive BLOB deletes moved to after commit via deleteExportArchiveBlobs() with report/report_unless. The original ordering had the compensation backwards: blobs were deleted before the transaction, so a rollback left the file already gone. Blobs-after-commit matches .ai/rules/retention.md and DeleteUploadedDocumentAction.

Approved scope addition: RefuseDataSubjectRequestAction had the identical unlocked guard and is fixed the same way.

Dropped a rejected idea: lockIfOpen() initially threw when DB::transactionLevel() === 0. RefreshDatabase already wraps every test in a transaction, so that branch is unreachable from the suite — untestable defensive code that would surface as an uncovered mutant and drag on the 87% coverage floor. The contract is documented on the method instead, and both call sites are covered.

Tests:
- New tests/Feature/Platform/DataSubjectRequestTransitionsTest.php, covers(DataSubjectRequestTransitions::class): canClose across all three statuses as a dataset; lockIfOpen returns the locked row, returns null when the row was closed behind the caller's back, and emits the lock query (for update asserted under pgsql only); complete() clears the archive columns; refuse() truncates the reason to 500.
- tests/Feature/Retention/DataSubjectErasureTest.php gained three tests: a double submit erases once (exactly one DataSubjectErasureCompleted entry, completed_by stays the first operator), a refusal cannot win a race against a completed fulfilment, and the erasure locks the request row before ANY write to data_subject_requests including the archive columns.

Two vacuous tests were caught and fixed during verification, both worth recording:
1. The first double-submit test reused ONE model instance. The old code mutated that instance in place, so the race never reproduced. Rewritten to hydrate two separate reads of the same row, which is what two concurrent POSTs actually hold. Re-verified: with the stale-instance guard restored, both race tests fail with 'ValidationException not thrown'.
2. The archive-ordering test first matched the lock as 'first select from data_subject_requests' — but clearExportArchiveRows() also selects from that table, so the sweep was mistaken for the lock and the assertion held even with the defect reintroduced. Now matched on the primary-key predicate. Re-verified: moving archive clearing back outside the transaction fails with 'Failed asserting that 3 is less than 2'.

Verified: 298 tests pass across tests/Feature/Retention, tests/Feature/Platform, tests/Architecture and tests/Unit; PHPStan level 8, Rector, Pint clean; pgsql run on the scratch database reports 66 assertions vs 62 on SQLite, the difference being the two 'for update' assertions actually executing.
<!-- SECTION:NOTES:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: @claude
created: 2026-08-14 14:45
---
Scope addition approved by the user: RefuseDataSubjectRequestAction:33 has the identical unlocked guard (isOpen() checked outside the transaction on the caller-supplied instance), so a refuse racing a fulfil leaves a request both Completed and Refused. Fixed in the same commit rather than filed separately, because leaving it would only close half the race AC#1 describes.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Anonymization is now a single locked transition, and so is its sibling refusal.

New DataSubjectRequestTransitions owns the guard: lockIfOpen() locks the request row and re-reads its status, returning null when another operator already closed it. AnonymizeDataSubjectAction and RefuseDataSubjectRequestAction both keep a cheap pre-check for the sequential case, then re-check under the lock inside the transaction and work on the locked instance. The erasure is now one transaction covering the activity purge, grant deletes, archive-column clearing, anonymisation, status change and audit entry; archive blobs are deleted after commit with report_unless, which also fixes the inverted compensation in the original (blobs were deleted before the transaction, so a rollback left the file already gone).

Verified: 298 tests pass across Retention, Platform, Architecture and Unit; PHPStan level 8, Rector and Pint clean; a Postgres run reports 66 assertions vs 62 on SQLite, the extra two being the 'for update' assertions actually executing.

Both new race tests were confirmed non-vacuous by restoring the old guard (both fail with 'ValidationException not thrown'), and the archive-ordering test by moving archive clearing back outside the transaction (fails on the index comparison). Two initially-vacuous test formulations were found and corrected in the process — reusing one model instance, and matching the lock query too loosely — and are documented in the implementation notes.
<!-- SECTION:FINAL_SUMMARY:END -->
