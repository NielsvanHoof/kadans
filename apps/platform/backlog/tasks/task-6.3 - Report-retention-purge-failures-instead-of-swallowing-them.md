---
id: TASK-6.3
title: Report retention purge failures instead of swallowing them
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 14:04'
labels: []
dependencies: []
references:
  - app/Platform/Actions/Retention/PurgeExpiredDossierAction.php
  - app/Platform/Actions/Retention/DeleteStoredDocumentFilesAction.php
modified_files:
  - app/Platform/Actions/Retention/PurgeExpiredDossierAction.php
  - tests/Feature/Retention/PurgeLegalRetentionTest.php
parent_task_id: TASK-6
priority: high
type: bug
ordinal: 24000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The expired-dossier purge catches Throwable and returns a result array saying nothing was purged, with no report, no log and no rethrow. A legal or GDPR retention purge failing silently is exactly the case you need told about, and the operator sees a successful no-op.

Its own collaborators - the stored-document and dossier-export file deleters - both report the exception correctly, so this is inconsistent within the same call chain.

The action also deletes files before opening its transaction, so a transaction failure destroys the files while leaving the dossier row intact.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A purge failure is reported to the error tracker and logged rather than returning a silent no-op
- [x] #2 File deletion happens after the transaction commits, so a rollback cannot destroy files for a dossier that still exists
- [x] #3 A test asserts a failing purge is reported and does not present as success
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Rewrite PurgeExpiredDossierAction: run DB transaction first (activity, pipelines, forceDelete), then delete storage files after commit.
2. On Throwable: report() + Log::error with dossier_id, then rethrow (no silent purged:false).
3. Test: force mid-purge failure; assert exception, reported/logged, dossier+files intact; existing PurgeLegalRetentionTest still passes.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Retention dossier purge now reports/logs failures and deletes storage only after the DB transaction commits.

## Changes
- PurgeExpiredDossierAction: transaction first, then file deleters; catch reports + Log::error + rethrow (no silent purged:false).

## Tests
PurgeLegalRetentionTest: failing purge is reported/logged, dossier+files remain; existing purge tests still pass.
<!-- SECTION:FINAL_SUMMARY:END -->
