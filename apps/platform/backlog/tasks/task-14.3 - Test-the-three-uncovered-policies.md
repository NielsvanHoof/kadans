---
id: TASK-14.3
title: Test the three uncovered policies
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:14'
labels: []
dependencies: []
references:
  - app/ClientPortal/Policies/ClientAccessGrantPolicy.php
  - app/Tenancy/Policies/TenantInvitationPolicy.php
  - app/Tenancy/Policies/TenantRolePolicy.php
  - tests/Feature/Tenancy
  - tests/Feature/Platform/AuthorizationCoverageTest.php
parent_task_id: TASK-14
priority: medium
type: enhancement
ordinal: 52000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Nine of twelve domain policies have a test file. Three do not: the client access grant policy, the tenant invitation policy and the tenant role policy. Their only appearance in the test suite is an assertion that they are registered, which proves wiring rather than behaviour.

The tenant role policy guards custom role creation and deletion, which is the privilege-escalation surface covered by the role self-escalation task - so if that task lands first, this is where its regression test belongs.

Also uncovered: the portal uploaded-document destroy route is listed in the authorization coverage map with a written justification, but has no behavioural test. It is the one portal mutation with no coverage.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 All three policies have test files covering allowed and forbidden cases, following the existing membership policy test pattern
- [x] #2 The portal uploaded-document destroy route has a behavioural test including the forbidden case
- [x] #3 Each test asserts cross-tenant denial, not just permission absence
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Read existing membership/policy test pattern + AuthorizationCoverageTest map.
2. Add Feature tests for ClientAccessGrantPolicy, TenantInvitationPolicy, TenantRolePolicy (allow + forbid + cross-tenant).
3. Add behavioural test for portal uploaded-document destroy (allowed + forbidden + cross-tenant).
4. Run the new Pest files via sail.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
TenantRolePolicy previously authorized update/delete solely against the current tenant; tightened to the role's tenant_id so cross-tenant denial is real.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Behavioural coverage for the three previously unwired policies and portal uploaded-document destroy isolation.

- New `ClientAccessGrantPolicyTest` and `TenantInvitationPolicyTest` (allow, forbid, outsider, cross-tenant).
- Extended `TenantRolePolicyTest` with cross-tenant denial; fixed `TenantRolePolicy` so update/delete check ManageRoles on the role's tenant, not only the current session.
- New `PortalUploadedDocumentDestroyTest` for cross-dossier and cross-tenant destroy → 404 (happy/under-review already in MultiFileUploadTest).

Evidence: `vendor/bin/sail artisan test --compact` on the four files — 17 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
