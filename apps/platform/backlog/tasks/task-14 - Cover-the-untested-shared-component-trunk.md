---
id: TASK-14
title: Cover the untested shared component trunk
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:16'
labels: []
dependencies: []
documentation:
  - docs/testing.md
priority: medium
type: enhancement
ordinal: 49000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The Vitest suite tests the leaves and skips the trunk. The most-reused components have no tests at all: the whole data folder including the data table, the 254-line pagination component, the 247-line query toolbar and the 244-line sortable list; the forms folder including the 331-line file dropzone and its upload validation; the feedback folder including the destroy-confirmation dialog with its genuinely tricky controlled-versus-uncontrolled and form-versus-callback branching.

Meanwhile eleven Vitest files cover the document requests feature alone. Features with no tests at all: clients, members, media, invitations, platform, workspaces.

There is also no coverage floor on the frontend, while PHP is gated at 87 percent - so this gap cannot fail a build and can silently widen.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Work subtasks in order:
1. 14.1 Tests for upload validation, destroy dialog, pagination, query toolbar, sortable list
2. 14.2 Vitest coverage floor (exclude generated/vendored; threshold at current level)
3. 14.3 Policy + portal destroy behavioural tests
4. 14.4 Decide: smoke browser tests OR strip dead data-test + fix Playwright deps
Finalize each then parent.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Covered the untested shared component trunk and related quality gaps (14.1–14.4).

1. **14.1** Vitest for upload validation, ConfirmDestroyDialog (plus open-state fix), pagination, query toolbar, sortable list.
2. **14.2** Vitest line coverage floor at 36% with generated/ui exclusions; wired into `ci:check` and Platform CI.
3. **14.3** Policy behaviour tests + portal destroy isolation; TenantRolePolicy cross-tenant fix.
4. **14.4** Removed dead `data-test` scaffolding; moved Playwright/concurrently to devDependencies.
<!-- SECTION:FINAL_SUMMARY:END -->
