---
id: TASK-13.2
title: Extract a shared index card from the five list cards
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 16:50'
labels: []
dependencies: []
references:
  - resources/js/features/clients
  - resources/js/features/dossiers
  - resources/js/features/pipelines
  - resources/js/components/data/data-table.tsx
parent_task_id: TASK-13
priority: medium
type: enhancement
ordinal: 47000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Five list cards - clients, archived clients, dossiers, archived dossiers and pipelines - total 742 lines and share one shape: card wrapper, data table, empty state, bulk-action toolbar wiring. Normalising the domain nouns, the difference between the dossier and client cards is the column definitions plus one bulk-action button.

Each card should become a short column specification over one shared container.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 One shared index card component wraps the table, empty state and bulk actions
- [x] #2 Each of the five list cards is reduced to its column and action specification
- [x] #3 Behaviour of all five lists is unchanged, including bulk selection and empty states
- [x] #4 Tests cover the shared component
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add components/data/index-data-card.tsx: Card header + DataTable; optional bulkActions render prop owns selection state and toolbar.
2. Refactor clients, archived-clients, dossiers, archived-dossiers, pipelines list cards to column/empty/bulk specs only.
3. Vitest for IndexDataCard (empty state, selection toolbar when bulkActions set).
4. Run the new test.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Added `IndexDataCard` (card + DataTable + optional bulkActions selection). Refactored clients, archived clients, dossiers, archived dossiers, and pipelines list cards to column/empty/bulk specs only.

## Tests
index-data-card.test.tsx — 2 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
