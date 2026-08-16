---
id: TASK-12.4
title: Fix the ambiguous role select labels in the members table
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 16:37'
labels: []
dependencies: []
references:
  - resources/js/features/members/members-list-card.tsx
parent_task_id: TASK-12
priority: medium
type: bug
ordinal: 44000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The members list card sets a correct visually-hidden field label naming the member, and then also sets an aria-label of just Role on the same control. The aria-label wins, so in a forty-member table every role select announces identically and a screen-reader user cannot tell whose role they are changing.

The fix is to delete the two redundant aria-label props and let the existing correct label apply.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Each role select announces which member it belongs to
- [x] #2 The existing visually-hidden label is the single source of the accessible name
- [x] #3 A test asserts the accessible name includes the member name
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Remove the two redundant aria-label={t('Role')} props from MemberRoleSelect (Select and SelectTrigger).
2. Keep the sr-only FieldLabel “Role for :name”.
3. Add Vitest asserting the combobox accessible name includes the member name.

Adjustment: RAC Select ignores htmlFor on the trigger; FieldLabel gets an id and Select uses aria-labelledby={roleLabelId}.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
htmlFor alone was insufficient for RAC Select; wired aria-labelledby to the existing sr-only FieldLabel instead of restoring a generic aria-label.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Removed the generic `aria-label="Role"` props that overrode the member-specific name. React Aria Select does not pick up htmlFor alone, so the sr-only FieldLabel now has an id and the Select uses `aria-labelledby` — single source of the accessible name including the member.

## Tests
members-list-card-role-label.test.tsx — passed.
<!-- SECTION:FINAL_SUMMARY:END -->
