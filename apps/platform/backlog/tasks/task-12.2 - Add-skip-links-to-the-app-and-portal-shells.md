---
id: TASK-12.2
title: Add skip links to the app and portal shells
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 16:33'
labels: []
dependencies: []
references:
  - resources/js/components/app-shell
  - resources/js/layouts
  - resources/js/hooks/use-inertia-navigation-effects.ts
parent_task_id: TASK-12
priority: high
type: bug
ordinal: 42000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Five layouts define the main-content anchor target and the Inertia navigation hook focuses it, but only on client-side navigations. On first page load a keyboard user has to tab through the entire sidebar before reaching content, and there is no skip link anywhere in the frontend or the root Blade view.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A keyboard user can jump to main content as the first tab stop on first page load
- [x] #2 The skip link is visually hidden until focused
- [x] #3 Both the staff shell and the client portal shell have one
- [x] #4 A test or browser check covers the first-tab-stop behaviour
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add a small SkipToMainContent link component (href=#main-content, sr-only until focused, translated label).
2. Render it as the first child in AppSidebarLayout (staff) and PortalShell (portal).
3. Add en/nl translation key for the skip label.
4. Vitest: render the link; assert it is the first tab stop and targets #main-content; assert focus styles unhide it (or at least that it receives focus).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Reused existing lang key Skip to content (en/nl). No new translations.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Added SkipToMainContent primitive (reuses existing “Skip to content” i18n). Mounted as the first child in AppSidebarLayout and PortalShell so the first Tab reaches main content on first load.

## Tests
`skip-to-main-content.test.tsx` — first tab stop + href #main-content + sr-only/focus:not-sr-only classes.
<!-- SECTION:FINAL_SUMMARY:END -->
