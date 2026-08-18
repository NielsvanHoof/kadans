---
id: TASK-36.8
title: Batch retention activity deletes and document-request reorders
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:34'
updated_date: '2026-08-14 22:29'
labels:
  - audit
dependencies: []
references:
  - app/Platform/Actions/Retention/PurgeSubjectActivityLogAction.php
  - app/Platform/Actions/Retention/PurgeExpiredDossierAction.php
  - app/Dossiers/Actions/Builder/ReorderDocumentRequestsAction.php
modified_files:
  - app/Platform/Actions/Retention/PurgeSubjectActivityLogAction.php
  - app/Dossiers/Actions/Builder/ReorderDocumentRequestsAction.php
  - tests/Feature/Retention/BatchedRetentionWritesTest.php
parent_task_id: TASK-36
priority: medium
type: enhancement
ordinal: 7000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two interactive or daily paths issue one statement per row inside a held lock.

PurgeSubjectActivityLogAction loops four times with one DELETE per id; a dossier with 125 children is 125 statements inside the transaction opened at PurgeExpiredDossierAction, so activity_log lock duration scales with child count. Runs up to 50× per tenant daily. The subject index already supports a whereIn.

ReorderDocumentRequestsAction issues one UPDATE per item inside lockForUpdate on the dossier; a 60-item drag-and-drop is 60 round trips on an interactive endpoint.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Purging subject activity for a dossier with many children does not issue one DELETE per id inside the outer purge transaction
- [x] #2 Reordering document requests does not issue one UPDATE per item while the dossier is lockForUpdate
- [x] #3 Existing activity_log subject indexes remain sufficient for the batched delete
- [x] #4 Focused tests cover a multi-child purge and a multi-item reorder
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented.

PurgeSubjectActivityLogAction: five per-row DELETE loops collapsed into one purgeForSubjectIds(morphClass, ids) helper doing chunked whereIn deletes (DELETE_CHUNK_SIZE = 500, bounded so a huge subtree cannot build one enormous IN list and so statements stay under SQLite's bound-parameter limit in tests). Built on Activity::query() and terminated on toBase() so model scopes still apply, matching the idiom in AnonymizeDataSubjectAction. AC#3 holds: the existing (subject_type, subject_id) composite from nullableMorphs() serves whereIn unchanged, so no new index. Empty child types now cost zero statements instead of an empty loop.

ReorderDocumentRequestsAction: the per-item UPDATE loop is one bound statement. Two implementation notes worth keeping:
- The first attempt used DB::raw() with a sprintf('%d') CASE. PHPStan level 8 rejected it (Connection::raw expects literal-string). Swapping to new Expression() would only have dodged the check, so the statement is now fully parameterised via DB::update() — every id and position is a placeholder and no escaping question arises at all. Only the table name is composed, and it comes from the model. updated_at is set explicitly because a bound statement skips Eloquent's timestamp handling and the per-row update it replaces did touch it.
- PHPStan also surfaced a genuine latent bug: $ids was array<int>, not list<int>, because array_map preserves keys. The loop index becomes sort_order, so a payload with non-sequential keys would have written gaps or duplicates. Fixed with array_values() before array_map, and covered by a dedicated test.

Deliberately NOT changed: SyncDossierDocumentRequestsAction, which the mapping flagged as having the same shape. It does not — each row there receives distinct title/instructions/configuration/type, so its writes are not collapsible into one statement. Only sort_order-only updates are.

Tests in tests/Feature/Retention/BatchedRetentionWritesTest.php, covers(PurgeSubjectActivityLogAction, ReorderDocumentRequestsAction): a 12-row subtree purge issues 3 DELETEs (dossier + two populated child types) and still reports the right deleted count; a 10-item reorder issues 1 UPDATE and persists the order; the partial-list rejection still holds; and a non-sequential-key payload writes contiguous positions.

Both confirmed non-vacuous by restoring the per-row loops: the purge test fails with 'actual size 13 matches expected size 3' and the reorder with 'actual size 10 matches expected size 1'.

Verified: 212 tests pass across Retention, Dossiers and DocumentRequests; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Both per-row write loops are now batched.

Activity purging collapses five per-id DELETE loops into chunked whereIn deletes, so statement count tracks subject types rather than row count — a 12-row subtree goes from 13 statements to 3, inside the transaction PurgeExpiredDossierAction holds. The existing (subject_type, subject_id) composite index serves whereIn unchanged.

Reordering writes every position in one bound statement instead of one UPDATE per item while the dossier is locked. It is fully parameterised rather than a DB::raw CASE: PHPStan rejected the raw form, and new Expression() would only have dodged the check rather than removed the interpolation.

PHPStan also surfaced a real latent bug in the process — the id array was not a list, so a payload with non-sequential keys would have written gaps or duplicate sort orders. Fixed and covered.

Verified: 4 tests, both statement-count assertions confirmed to fail when the loops are restored (13 vs 3, 10 vs 1). 212 tests pass across Retention, Dossiers and DocumentRequests; PHPStan level 8, Rector and Pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
