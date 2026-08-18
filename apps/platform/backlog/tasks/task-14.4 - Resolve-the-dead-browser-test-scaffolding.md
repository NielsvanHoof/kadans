---
id: TASK-14.4
title: Resolve the dead browser-test scaffolding
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:16'
labels: []
dependencies: []
references:
  - tests/Browser
  - package.json
parent_task_id: TASK-14
priority: low
type: chore
ordinal: 53000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Twenty-seven data-test attributes are scattered through the frontend with zero consumers - grepping the test suite for data-test returns nothing. The browser test directory exists and is empty, the Pest browser plugin is in dev dependencies, and Playwright is installed but sits in production dependencies rather than dev.

Somebody wired up the selectors and stopped. Right now it is a maintenance tax pretending to be coverage. Either use it or remove it - a decision, not a half-state.

If writing tests, the highest-value three are login, the dossier list and the dossier show page, which is also the manual path an interviewer would click through.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Either browser smoke tests exercise the existing selectors, or the unused attributes are removed
- [x] #2 Playwright and concurrently are in dev dependencies rather than production dependencies
- [x] #3 If tests are written, screenshot output is gitignored and they run in CI
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Decision: strip dead scaffolding rather than write browser smoke tests (Pest browser plugin needs Playwright binaries; unused data-test attrs are a tax).
1. Inventory data-test usages and tests/Browser.
2. Remove unused data-test attributes (keep if any real consumer).
3. Move playwright + concurrently to devDependencies.
4. Leave tests/Browser empty or remove if unused; do not add CI browser job unless writing tests.
5. Document decision in task notes.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Decision: strip unused selectors rather than invent smoke tests without product buy-in. pest-plugin-browser stays in require-dev.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Chose removal over half-written browser coverage.

- Removed unused `data-test` / `confirmTestId` scaffolding from auth, settings, clients, dossiers, app-shell, bulk toolbar, and ConfirmDestroyDialog.
- Moved `playwright` and `concurrently` from dependencies → devDependencies (lockfile updated).
- Removed empty `tests/Browser/` directory. Left `pestphp/pest-plugin-browser` in require-dev for a future deliberate smoke suite.
- AC #3 N/A: no browser tests written, so no screenshot CI wiring.

Evidence: no `data-test=` left under resources/js; lockfile shows both packages as root devDependencies; confirm-destroy + user-menu Vitest still pass.
<!-- SECTION:FINAL_SUMMARY:END -->
