---
id: TASK-6
title: 'Fix correctness gaps in async processing, retention and multi-write actions'
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 14:09'
labels: []
dependencies: []
documentation:
  - docs/backend.md
priority: high
type: bug
ordinal: 21000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Five contained correctness bugs. The surrounding code is unusually careful - the processing jobs use locking claims, stale-revision detection and compensating storage deletes - which is what makes these specific gaps stand out as oversights rather than a pattern.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Work subtasks sequentially: 6.1 uniqueFor locks → 6.2 document deletion → 6.3 retention purge → 6.4 swallowed exceptions → 6.5 invitation/role transactions. Finalize each subtask before the next; close parent when all Done.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Closed all five correctness gaps under TASK-6: unique job lock bounds, transactional document deletion with guarded reopen, retention purge reporting/ordering, surfaced swallowed exceptions, and transactional invitation/role writes.

## Subtasks
- 6.1 uniqueFor on OCR/transcription/export jobs
- 6.2 DeleteUploadedDocumentAction + reopenForSubmission
- 6.3 PurgeExpiredDossierAction report/log + files after commit
- 6.4 activity date validation, reminder disable logging, role sync report
- 6.5 invitation register + role create/update transactions
<!-- SECTION:FINAL_SUMMARY:END -->
