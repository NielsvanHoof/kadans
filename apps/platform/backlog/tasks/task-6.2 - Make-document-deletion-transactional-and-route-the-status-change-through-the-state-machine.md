---
id: TASK-6.2
title: >-
  Make document deletion transactional and route the status change through the
  state machine
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 14:03'
labels: []
dependencies: []
references:
  - app/Dossiers/Actions/DocumentRequests/DeleteUploadedDocumentAction.php
  - app/Dossiers/Actions/DocumentRequests/UploadDocumentForRequestAction.php
  - app/Dossiers/Transitions/DocumentRequestTransitions.php
modified_files:
  - app/Dossiers/Actions/DocumentRequests/DeleteUploadedDocumentAction.php
  - app/Dossiers/Transitions/DocumentRequestTransitions.php
  - tests/Feature/Dossiers/DeleteUploadedDocumentActionTest.php
  - tests/Feature/DocumentRequests/DocumentRequestTransitionsTest.php
  - tests/Feature/Dossiers/MultiFileUploadTest.php
parent_task_id: TASK-6
priority: high
type: bug
ordinal: 23000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The delete-uploaded-document action deletes the storage object first, then deletes the pipelines, then the model row, then conditionally rewrites the parent document request status.

Three problems in fifteen lines:
- no transaction, so a failure on either delete leaves a row pointing at an object that no longer exists
- the storage delete runs before the database writes, the exact inverse of the ordering used by its own sibling upload actions, which write the file, open a transaction, and compensate by deleting the file on rollback
- it writes the Pending status directly, and that transition does not exist in `DocumentRequestTransitions` - so it is the one status change in the domain with no guard, which the backend conventions explicitly warn against
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The database writes happen inside a transaction and the storage object is only deleted after it commits
- [x] #2 A failure part-way through leaves neither an orphaned storage object nor a row pointing at a deleted object
- [x] #3 Reopening a submitted document request goes through a guarded DocumentRequestTransitions method rather than a direct status write
- [x] #4 A test covers the rollback path and asserts no orphaned storage object remains
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add DocumentRequestTransitions::reopenForSubmission() (or similar) — Submitted → Pending with answered_at null; guard only Submitted and only when no remaining uploads (caller checks emptiness, or transition receives that context).
2. Rewrite DeleteUploadedDocumentAction: capture disk/path; DB::transaction deletes pipelines + model + optional reopen transition; after commit delete storage file (report failures like upload sibling).
3. On transaction failure, storage file remains (AC #2).
4. Feature test: mock pipeline delete or force exception mid-transaction; assert Storage still has file and DB row intact; happy path asserts file gone and Pending via transitions.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Made uploaded-document deletion transactional and routed reopen-to-pending through DocumentRequestTransitions.

## Changes
- DeleteUploadedDocumentAction: DB transaction for pipelines + row (+ reopen); storage delete only after commit.
- DocumentRequestTransitions::reopenForSubmission(): Submitted to Pending with answered_at cleared.

## Tests
DeleteUploadedDocumentActionTest (happy path + rollback keeps storage); transition reopen tests; MultiFileUpload answered_at.
<!-- SECTION:FINAL_SUMMARY:END -->
