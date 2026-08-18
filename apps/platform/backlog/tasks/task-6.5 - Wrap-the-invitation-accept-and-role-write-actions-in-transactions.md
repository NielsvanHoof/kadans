---
id: TASK-6.5
title: Wrap the invitation-accept and role write actions in transactions
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 14:09'
labels: []
dependencies: []
references:
  - app/Tenancy/Actions/RegisterAndAcceptTenantInvitationAction.php
  - app/Tenancy/Actions/AcceptTenantInvitationAction.php
  - app/Tenancy/Actions/CreateTenantRoleAction.php
  - app/Tenancy/Actions/UpdateTenantRoleAction.php
modified_files:
  - app/Tenancy/Actions/RegisterAndAcceptTenantInvitationAction.php
  - app/Tenancy/Actions/CreateTenantRoleAction.php
  - app/Tenancy/Actions/UpdateTenantRoleAction.php
  - tests/Feature/Tenancy/InvitationAndRoleTransactionTest.php
  - lang/en.json
  - lang/nl.json
parent_task_id: TASK-6
priority: high
type: bug
ordinal: 26000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two multi-write actions have no transaction.

The register-and-accept-invitation action creates the user, force-marks the email verified, dispatches Registered, logs the user in, and then accepts the invitation - which itself creates a membership, assigns a role and marks the invite accepted. A failure in that last step leaves a verified, logged-in user with no membership and a still-open invitation. The login also happens before the membership exists and cannot be rolled back.

The create and update tenant role actions create the role and then sync its permissions as a separate multi-row pivot write. A failure between the two leaves an empty role that owners can assign.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Invitation acceptance is atomic: a failure leaves no user, no membership and the invitation still open
- [x] #2 Authentication happens after the transaction commits rather than inside it
- [x] #3 Role creation and update are atomic, so a permission sync failure leaves no empty role
- [x] #4 Tests cover a failure mid-way through both flows
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. RegisterAndAcceptTenantInvitationAction: DB::transaction for user create + verify + accept; dispatch Registered + Auth::login after commit.
2. CreateTenantRoleAction / UpdateTenantRoleAction: wrap create/update + syncPermissions (+ audit) in DB::transaction.
3. Feature tests: force mid-flow failure (model event or mock) asserting rollback for invite register and role create.
4. Add en/nl keys for activity log date validation messages; run LanguageCoverageTest.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Wrapped invitation register/accept and tenant role create/update in DB transactions so mid-flow failures roll back cleanly.

## Changes
- RegisterAndAcceptTenantInvitationAction: create+verify+accept in transaction; Registered + Auth::login after commit.
- CreateTenantRoleAction / UpdateTenantRoleAction: create/update + syncPermissions + audit in transaction.
- Added en/nl strings for activity-log date validation (from 6.4).

## Tests
InvitationAndRoleTransactionTest covers rollback for invite register, role create, and role update.
<!-- SECTION:FINAL_SUMMARY:END -->
