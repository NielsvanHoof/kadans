---
id: TASK-12.1
title: Give card titles a real heading level
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 16:32'
labels: []
dependencies: []
references:
  - resources/js/components/ui/card.tsx
  - resources/js/components/primitives/heading.tsx
parent_task_id: TASK-12
priority: high
type: bug
ordinal: 41000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The shadcn card title primitive renders a div. It is used thirty-seven times across the application against five uses of the heading primitive at level two.

The result is that every detail page - dossier show, client show, workspace members, all the settings pages - presents as a single h1 followed by six to eight unlabelled regions. Screen-reader heading navigation, which is how many users navigate a page, is useless on all of them.

The Tailwind classes do not need to change; only the rendered element does.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Card titles render as a heading element, defaulting to level two, with the level overridable
- [x] #2 Detail pages present a correct heading outline under h1 rather than a flat list of unlabelled regions
- [x] #3 Visual appearance is unchanged
- [x] #4 A test asserts the rendered element is a heading
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Change CardTitle in resources/js/components/ui/card.tsx to render h2 by default; accept optional level (1–6) to pick the heading tag; keep existing Tailwind classes.
2. Type props as heading props + level (not div).
3. Add Vitest at resources/js/components/ui/tests/card-title.test.tsx asserting default h2 and overridable level.
4. Run the new test via npm/sail.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verified: Vitest asserts default h2 and level={3} override. Classes remain text-sm font-medium.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
CardTitle now renders a real heading (`h2` by default) with an optional `level` prop (1–6). Tailwind classes unchanged so visuals stay the same; all existing CardTitle call sites inherit a proper outline under page h1s.

## Tests
`vendor/bin/sail npm test -- --run resources/js/components/ui/tests/card-title.test.tsx` — 2 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
