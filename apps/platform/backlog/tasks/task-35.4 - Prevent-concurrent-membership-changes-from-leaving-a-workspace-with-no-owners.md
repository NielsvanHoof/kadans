---
id: TASK-35.4
title: Prevent concurrent membership changes from leaving a workspace with no owners
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-13 11:33'
updated_date: '2026-08-13 14:18'
labels:
  - audit
dependencies: []
references:
  - app/Tenancy/Actions/RemoveTenantMemberAction.php
  - app/Tenancy/Actions/SuspendTenantMemberAction.php
  - app/Tenancy/Actions/UpdateTenantMemberRoleAction.php
  - app/Tenancy/Support/WorkspaceMemberRoles.php
  - tests/Feature/Tenancy/LastOwnerMutationLockTest.php
parent_task_id: TASK-35
priority: high
type: bug
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Remove, suspend, and role-update of tenant members lock only the target membership row, then count active owners with an unlocked query. Two concurrent mutations on different rows (remove owner A, demote owner B) both see count=2 under READ COMMITTED and both commit — zero owners. Nobody can then invite or assign the owner role (actorCanAssignRole requires an existing owner); recovery needs shell access to tenancy:create-owner. Demote is doubly exposed: syncRole() writes model_has_roles, so the membership row lock does not serialize it.

WorkspaceMemberRoles::lockMembershipPair() already serializes the accept path on more than one row — follow that tenant-level serialization idea, not a new locking framework. TASK-21 only corrected last-owner tests and an export uniqueFor window; it did not close this race.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Removing, suspending, or demoting a member cannot leave a workspace with zero active owners under concurrent requests
- [x] #2 The last-owner count is taken under a tenant-scoped lock, not only a lock on the target membership row
- [x] #3 A test asserts the lock is taken (query log or advisory-lock call); do not attempt true in-process concurrency
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Serialize last-owner checks on the tenant row, not pg_advisory_xact_lock (SQLite tests omit Postgres advisory locks; Laravel SQLite grammar also omits FOR UPDATE).

1. Add WorkspaceMemberRoles::lockOwnerMutations(int $tenantId): lock the tenants row with lockForUpdate(), selecting only id so the query is distinct from loadMissing('tenant') in the query log. Callers must already be inside DB::transaction(). Follow lockMembershipPair() (query builder lock), not a new locking helper type.
2. Call lockOwnerMutations($membership->tenant_id) at the start of Remove/Suspend/UpdateTenantMemberRole transactions, before the target membership lockForUpdate — tenant-first lock order avoids deadlock if two txs lock different memberships then both wait on the tenant.
3. Leave isLastActiveOwner()/activeOwnerCount() unlocked: Policy and FormRequest call them outside a transaction. The action transaction remains the last line of defence.
4. Feature test (dataset remove/suspend/demote): two owners, mutate the second, assert query log contains select id from tenants (and for update on pgsql). Do not spawn concurrent PHP processes. Existing HTTP last-owner tests stay.
5. record-rule on Tenancy Actions: last-owner mutations lock the tenant row first.

Implementation note: lockOwnerMutations uses the query builder (not Eloquent select) because phpstan-strict-rules rejects Tenant::query()->select() as a dynamic call.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Used DB::table('tenants')->lockForUpdate()->first(['id']) instead of Eloquent select/firstOrFail: phpstan-strict-rules flags Builder::select() as staticMethod.dynamicCall. Query-builder lockForUpdate matches lockMembershipPair().

Pest LastOwnerMutationLockTest 3 passed; WorkspaceMemberManagementTest + TenantMembershipPolicyTest 24 passed together. PHPStan 0 errors on the four Tenancy files.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Prevent concurrent remove/suspend/demote from leaving a workspace with zero owners.

RemoveTenantMemberAction, SuspendTenantMemberAction, and UpdateTenantMemberRoleAction now call WorkspaceMemberRoles::lockOwnerMutations($tenantId) at the start of their transaction, before the target membership lockForUpdate. That takes a lockForUpdate on the tenants row (select id only), so two mutations on different owners serialize on the same key instead of both seeing activeOwnerCount=2 under READ COMMITTED. Demote is covered even though syncRole() writes model_has_roles, which the membership row lock never serialized.

Did not use pg_advisory_xact_lock: the Pest suite runs SQLite, which neither supports advisory locks nor emits FOR UPDATE. isLastActiveOwner() stays unlocked so Policy and FormRequest can keep calling it outside a transaction; the action transaction is the last line of defence.

Tests: tests/Feature/Tenancy/LastOwnerMutationLockTest.php (dataset remove/suspend/demote) asserts the query log contains select id from tenants and that one active owner remains. WorkspaceMemberManagementTest and TenantMembershipPolicyTest still pass. PHPStan clean on the changed PHP. Rule recorded in .ai/rules/tenancy.md.
<!-- SECTION:FINAL_SUMMARY:END -->
