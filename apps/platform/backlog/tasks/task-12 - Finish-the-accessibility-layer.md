---
id: TASK-12
title: Finish the accessibility layer
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 16:37'
labels: []
dependencies: []
documentation:
  - docs/frontend.md
priority: high
type: bug
ordinal: 40000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Accessibility was started and left half-done. The good parts are real: all fourteen icon-only buttons carry aria labels, there are no clickable divs anywhere, and one shared empty-state primitive is used twenty-eight times. But four structural pieces are missing, each affecting every page rather than one screen.

Verified counts, not estimates: aria-sort appears zero times in the frontend; the card title primitive renders a div and is used thirty-seven times against five uses of the heading primitive at level two; there are eight references to the main-content anchor target and zero skip links.

This is finish-the-job work, not a rebuild.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Work subtasks in order:
1. 12.1 CardTitle → real heading (default h2, level overridable)
2. 12.2 Skip links on staff + portal shells
3. 12.3 Sortable table aria-sort via shared data-table
4. 12.4 Members role select accessible name
Finalize each subtask then parent.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Pipeline work (TASK-10/11/17 and related) parked per user; discarded uncommitted pipeline/arch changes. Picking up accessibility subtasks 12.1→12.4.

All subtasks 12.1–12.4 Done. Pipeline work remains parked (TASK-10/11/17).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Finished the accessibility layer across four shared surfaces.

### Subtasks
- **12.1** CardTitle renders `h2` by default (level overridable); visuals unchanged.
- **12.2** SkipToMainContent on staff AppSidebarLayout and PortalShell (first tab stop).
- **12.3** DataTable uses React Aria column sorting so headers expose `aria-sort`.
- **12.4** Members role selects use sr-only “Role for :name” via `aria-labelledby` (no generic Role aria-label).

## Tests
Vitest: card-title, skip-to-main-content, data-table-sort, members-list-card-role-label — 7 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
