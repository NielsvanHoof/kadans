---
id: TASK-13.1
title: Collapse the three role form dialogs into one
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 16:46'
labels: []
dependencies: []
references:
  - resources/js/features/roles/edit-role-dialog.tsx
  - resources/js/features/roles/duplicate-role-dialog.tsx
  - resources/js/features/roles/role-management-card.tsx
parent_task_id: TASK-13
priority: medium
type: enhancement
ordinal: 46000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The edit and duplicate role dialogs are 135 and 127 lines differing in about twenty - the title, the Wayfinder target, a field id and the button label. A third copy of the same name-plus-permission-checklist form is inline in the role management card for the create case.

The drift is already visible: the edit dialog shows a hint telling the user to choose at least one permission before saving, and the duplicate dialog silently omits it, so duplicating a role with no permissions gives no feedback at all.

One dialog with a mode removes roughly 250 lines and makes that divergence impossible.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 One dialog component serves create, edit and duplicate
- [x] #2 All three modes show the same validation guidance, including the choose-a-permission hint
- [x] #3 The two duplicated files are deleted
- [x] #4 Tests cover all three modes
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Create role-form-dialog.tsx with mode create|edit|duplicate (shared name field, PermissionChecklist, permission hint, submit disabled when no permissions).
2. Wire form action: store for create/duplicate, update for edit; default name empty / role.label / “:name copy”.
3. Slim RoleManagementCard: replace inline create form + Edit/Duplicate dialogs with one RoleFormDialog + Create button; delete edit-role-dialog.tsx and duplicate-role-dialog.tsx.
4. Vitest covering all three modes (titles, permission hint, submit disabled when empty).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Create moved from inline card form into the shared dialog so validation guidance cannot drift.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Collapsed create/edit/duplicate role forms into `RoleFormDialog` with a mode state. RoleManagementCard opens that dialog for all three flows; inline create form is gone. Deleted `edit-role-dialog.tsx` and `duplicate-role-dialog.tsx`. All modes show the choose-a-permission hint and disable submit when empty.

## Tests
role-form-dialog.test.tsx — 3 passed (create/edit/duplicate).
<!-- SECTION:FINAL_SUMMARY:END -->
