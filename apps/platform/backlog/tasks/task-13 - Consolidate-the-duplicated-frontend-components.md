---
id: TASK-13
title: Consolidate the duplicated frontend components
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 16:54'
labels: []
dependencies: []
documentation:
  - docs/frontend.md
priority: medium
type: enhancement
ordinal: 45000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Three families of copy-paste in the frontend. In each case the duplication has already caused observable drift, which is the argument for consolidating rather than leaving it.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Work subtasks in order:
1. 13.1 Collapse create/edit/duplicate role dialogs into one
2. 13.2 Extract shared index card from five list cards
3. 13.3 Unify status badges including pipelines
Finalize each subtask then parent.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
All subtasks 13.1–13.3 Done.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Consolidated three frontend duplication families.

### Subtasks
- **13.1** One `RoleFormDialog` for create/edit/duplicate; deleted edit/duplicate dialog files; shared permission hint.
- **13.2** `IndexDataCard` shared shell; five list cards reduced to column/bulk/empty specs.
- **13.3** `StatusBadge` primitive; pipelines use audit marks like dossiers/document requests.

## Tests
Vitest covering role-form-dialog, index-data-card, status-badge, pipeline-status-badge (+ related) — 36 passed in the focused run.
<!-- SECTION:FINAL_SUMMARY:END -->
