---
id: TASK-4
title: Close the role self-escalation path
status: Done
assignee:
  - cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 12:14'
labels: []
dependencies: []
references:
  - app/Tenancy/Support/WorkspaceMemberRoles.php
  - app/Tenancy/Policies/TenantMembershipPolicy.php
  - app/Tenancy/Http/Requests/UpdateTenantRoleRequest.php
  - app/Tenancy/Http/Requests/StoreTenantRoleRequest.php
  - app/Tenancy/Enums/WorkspacePermission.php
  - app/Tenancy/Http/Requests/Concerns/ValidatesAssignableRolePermissions.php
  - app/Tenancy/Support/PermissionRegistry.php
  - tests/Feature/Tenancy/TenantRolePolicyTest.php
  - tests/Feature/Tenancy/RoleSelfEscalationTest.php
  - tests/Feature/Tenancy/TenantMembershipPolicyTest.php
documentation:
  - docs/permissions.md
modified_files:
  - app/Tenancy/Policies/TenantMembershipPolicy.php
  - app/Tenancy/Support/PermissionRegistry.php
  - app/Tenancy/Http/Requests/Concerns/ValidatesAssignableRolePermissions.php
  - app/Tenancy/Http/Requests/StoreTenantRoleRequest.php
  - app/Tenancy/Http/Requests/UpdateTenantRoleRequest.php
  - docs/permissions.md
  - tests/Feature/Tenancy/TenantMembershipPolicyTest.php
  - tests/Feature/Tenancy/TenantRolePolicyTest.php
  - tests/Feature/Tenancy/RoleSelfEscalationTest.php
priority: high
type: bug
ordinal: 16000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
A workspace Administrator can escalate to effective owner without ever being assigned the owner role.

Three gaps combine:
- `actorCanAssignRole()` blocks only the owner role, nothing else
- `TenantMembershipPolicy::update` has no self-check, unlike `suspend` which does have one
- the store and update role requests validate submitted permissions against the whole `PermissionRegistry`, which includes the owner-only `roles.manage` and `tenant.settings.manage`

So as soon as an owner creates any custom role containing `roles.manage`, an Administrator - who holds `members.assign_role` by default - patches their own membership onto that role, then mints a new role holding every registered permission and assigns it to themselves.

This is semi-intentional: the permission enum description already says it grants privilege escalation. But the self-assignment path and the ability to put owner-only permissions into firm-authored roles are both avoidable, and `TenantRolePolicy` has no test file at all, so none of this is currently covered.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A member cannot change their own role assignment, mirroring the existing self-check on suspend
- [x] #2 Owner-only permissions cannot be included in a firm-authored role
- [x] #3 An actor can only grant permissions they already hold themselves
- [x] #4 TenantRolePolicy has a test file covering both allowed and forbidden cases, following the TenantMembershipPolicyTest pattern
- [x] #5 A test asserts an Administrator cannot patch their own membership onto a role carrying roles.manage
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. TenantMembershipPolicy::update — reject when membership.user_id === actor (mirror suspend). Also add the same self-check to assignRole so the UpdateTenantMemberRequest after-hook cannot bypass it.
2. PermissionRegistry — add isOwnerOnly() (defaultRoles() === []) and firmAssignableValues() excluding owner-only permissions (roles.manage, tenant.settings.manage, and any future empty-defaultRoles cases).
3. StoreTenantRoleRequest + UpdateTenantRoleRequest — replace Rule::in(registry->values()) with the intersection of firmAssignableValues() and permissions the actor holds in the current tenant (hasTenantPermission). Share via a small Form Request concern to avoid duplication.
4. Tests:
   - Extend TenantMembershipPolicyTest: member cannot update/assignRole on their own membership.
   - New TenantRolePolicyTest (TenantMembershipPolicyTest pattern): allowed create/update/delete for owners; forbidden for admins; system roles blocked.
   - Feature coverage: owner cannot put roles.manage / tenant.settings.manage on a custom role; Administrator cannot patch own membership onto a custom role that somehow carries roles.manage (assertForbidden).
5. Brief docs/permissions.md note: firm-authored roles may only include non-owner-only permissions the actor holds.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Owner-only detection reuses the existing empty defaultRoles() convention (ManageRoles, ManageSettings) rather than a hard-coded allow-list.

AC #3 (actor may only grant permissions they hold) is enforced via hasTenantPermission against firmAssignableValues; for owners that is effectively the firm-assignable set since they hold everything.

Verification: 44/44 related tests passed. Pint fixed fully_qualified_strict_types in RoleSelfEscalationTest.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Closed the Administrator → effective-owner self-escalation path with three complementary controls:

1. Self-assignment blocked — TenantMembershipPolicy::update and assignRole reject when the membership belongs to the actor (same pattern as suspend).
2. Owner-only permissions excluded from firm-authored roles — PermissionRegistry treats empty defaultRoles() as owner-only and exposes firmAssignableValues(); Store/UpdateTenantRoleRequest validate against those values intersected with permissions the actor holds (ValidatesAssignableRolePermissions concern).
3. Coverage — TenantRolePolicyTest (allowed/forbidden/system roles), RoleSelfEscalationTest (cannot put roles.manage/settings.manage on custom roles; admin cannot patch self onto a planted escalation role), plus membership policy self-check. docs/permissions.md updated.

Verification: vendor/bin/sail artisan test --compact tests/Feature/Tenancy/TenantMembershipPolicyTest.php tests/Feature/Tenancy/TenantRolePolicyTest.php tests/Feature/Tenancy/RoleSelfEscalationTest.php tests/Feature/Tenancy/WorkspaceRolesPageTest.php tests/Feature/Tenancy/PermissionRegistryTest.php → 44/44 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
