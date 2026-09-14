---
id: TASK-60
title: Make the role permission picker usable at full width
status: In Progress
assignee: []
created_date: '2026-09-14 08:31'
updated_date: '2026-09-14 08:39'
labels: []
dependencies: []
priority: medium
type: feature
ordinal: 127000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The create/edit/duplicate role dialog inherits the global 384px dialog cap (sm:max-w-sm) while rendering roughly 50 permissions across 12 groups, each with a label and a description. Picking permissions means a multi-thousand-pixel scroll in a narrow column with no way to find a specific permission, and the save button ends up far below the fold. The host page is max-w-5xl, so the width is available and unused. Direction agreed with the product owner: keep the dialog (not a full page or side sheet), widen it, and add search, a live selected count, and collapsible groups.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The role form dialog renders at sm:max-w-4xl and permissions inside an expanded group lay out in 2-3 responsive columns
- [x] #2 A search field filters permissions by label and description across all groups, force-expands matching groups, and shows a written empty state when nothing matches
- [x] #3 An overall 'X of Y selected' summary and a per-group count are shown and update as permissions are toggled
- [x] #4 Permission groups collapse and expand individually, with Expand all / Collapse all controls; groups holding a selection start expanded
- [x] #5 Selections survive being filtered or collapsed out of view: the submitted payload comes from React state via hidden inputs, not from the rendered checkboxes
- [x] #6 Existing All/None per-group and Select all/Clear all behaviour is unchanged, and All/None still act on the full group rather than the filtered subset
- [x] #7 New UI copy exists in both app/Tenancy/lang/en.json and nl.json via lang:organize
- [x] #8 Frontend tests cover search, counts, collapse, and payload-survives-filtering; npm run types:check passes
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
The load-bearing change is that the checkboxes no longer carry name="permissions[]". Search unmounts non-matching permissions, and an unmounted input posts nothing, so an already-selected permission would have been silently dropped from the request. The payload now comes from one hidden input per entry in selectedPermissions, rendered by role-form-dialog.tsx. Collapse was safe either way (RAC's DisclosurePanel only sets hidden="until-found" and keeps children mounted), but search was not.

CollapsibleContent could not take role="presentation" as planned: RAC types the prop as group|region only. The panel therefore stays an unnamed role=group nested in the fieldset; labelling it would collide with the legend that already names the group and make getByRole('group', { name }) ambiguous.

Group layout is full-width collapsible rows with a 2-3 column permission grid inside, rather than the side-by-side group cards first sketched -- independently expanding cards in a grid reflow badly.

lang:organize only redistributes keys that already exist in a dictionary; it never invents them. The seven new keys were seeded into app/Tenancy/lang/{en,nl}.json and then canonicalised by the command.

Validation: vp test (699 passed, 131 files; 16 in features/roles incl. 7 new); npm run types:check clean with no codegen drift; vp check (lint + format) clean; artisan lang:organize --check reports up to date; tests/Unit/LanguageCoverageTest.php 7 passed; Feature/Tenancy role tests (WorkspaceRolesPage, RoleSelfEscalation, TenantRolePolicy) 31 passed.

AC #1 is left unchecked: the sm:max-w-4xl width and the 2-3 column grid are asserted only by code, not by a rendered measurement. tests/Browser is empty and unwired in this project (no APP_URL, in-memory SQLite), so a real-browser pass was not run.
<!-- SECTION:NOTES:END -->
