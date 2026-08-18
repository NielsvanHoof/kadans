---
id: TASK-1.1
title: Add pipelines.retry permission and policy gate
status: Done
assignee:
  - '@claude'
created_date: '2026-08-05 21:39'
updated_date: '2026-08-05 22:00'
labels: []
dependencies: []
references:
  - app/DocumentProcessing/Enums/PipelinePermission.php
  - app/DocumentProcessing/Policies/PipelinePolicy.php
  - app/Tenancy/Policies/Concerns/AuthorizesTenantPermission.php
documentation:
  - docs/permissions.md
parent_task_id: TASK-1
type: feature
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Introduce the tenant permission and policy method that gate manual pipeline retry, so the endpoint and UI added by later tasks have something to authorize against.

Add PipelinePermission::Retry = 'pipelines.retry' to app/DocumentProcessing/Enums/PipelinePermission.php, extending the existing label(), description(), and defaultRoles() match arms. group() already returns PermissionGroup::Processing for every case. Default roles are Administrator + Adviser, matching Delete; Owner is implicit and must never be listed. No config/tenant-permissions.php change is needed because the enum class is already registered there.

Add PipelinePolicy::retry(User, Pipeline). It must allow only PipelineStatus::Failed and PipelineStatus::Stuck. Deliberately do NOT use isTerminal() here, even though the sibling delete() does: isTerminal() also covers 'completed' and 'cancelled', which must stay closed. Use userHasPermissionInTenant() with the pipeline's tenant, as the other instance gates do.

Per docs/permissions.md, run tenancy:sync-roles after changing the enum, and permissions:generate so the frontend TenantPermission union picks up the new ability.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 PipelinePermission::Retry exists with value 'pipelines.retry', a label, a description, and PermissionGroup::Processing
- [x] #2 Administrator and Adviser receive the permission by default after tenancy:sync-roles; Reviewer and ReadOnly do not; Owner holds it implicitly
- [x] #3 The permission appears on the workspace roles matrix under the Processing group
- [x] #4 PipelinePolicy::retry() returns true only for failed or stuck pipelines in a tenant where the user holds pipelines.retry
- [x] #5 PipelinePolicy::retry() returns false for completed, running, cancelled, and pending pipelines regardless of role
- [x] #6 permissions:generate output is regenerated and committed, and npm run types:check passes
- [x] #7 Feature tests cover a permitted role, a denied role, and each non-retryable status
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add PipelinePermission::Retry = 'pipelines.retry' between View and Delete; extend the label(), description(), and defaultRoles() match arms. defaultRoles = [Administrator, Adviser]; group() already covers all cases via PermissionGroup::Processing. No config/tenant-permissions.php change (enum already registered).
2. Add PipelinePolicy::retry(User, Pipeline): allow only PipelineStatus::Failed or Stuck, combined with userHasPermissionInTenant($user, $pipeline->tenant, PipelinePermission::Retry). Explicitly not isTerminal().
3. Regenerate the frontend permission union: npm script permissions:generate maps to 'php artisan tenancy:generate-permissions' (note: not 'permissions:generate' as the task description says).
4. Run tenancy:sync-roles so existing tenants receive the grant.
5. Add tests/Feature/DocumentProcessing/PipelinePolicyTest.php following the UploadedDocumentPolicyTest pattern (instantiate the policy directly, provisionWorkspace/workspaceMember helpers): owner + administrator + adviser allowed on failed and stuck; reviewer and read-only denied; every non-retryable status (pending, running, completed, cancelled) denied even for an owner; cross-tenant denied.
6. Verify: pest on tests/Feature/DocumentProcessing and tests/Feature/Tenancy, pint --dirty, npm run types:check.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented PipelinePermission::Retry (defaultRoles Administrator + Adviser, sharing Delete's match arm) and PipelinePolicy::retry(), which gates on Failed|Stuck explicitly rather than isTerminal().

Added tests/Feature/DocumentProcessing/PipelinePolicyTest.php (11 tests, dataset-driven) following the UploadedDocumentPolicyTest pattern of instantiating the policy directly.

Environment gotchas worth carrying into later tasks:
- 'npm run types:check' cannot run on the host: its 'php artisan' steps fail with a RedisException (getaddrinfo for redis), and 'npx tsc' fails because node_modules were installed in the Linux container (missing @typescript/typescript-darwin-arm64). Run the steps through sail instead: 'vendor/bin/sail artisan ...' and 'vendor/bin/sail npx tsc --noEmit'.
- 'vendor/bin/sail bin pint --dirty' errors with 'The [--dirty] option is only available when using Git' because .git is not mounted in the container. Pass explicit file paths instead.
- IMPORTANT for TASK-1.4: regenerate Wayfinder with 'artisan wayfinder:generate --with-form'. The npm script uses --with-form; running the bare command strips the .form() variants and breaks ~20 files with TS2339 'Property form does not exist'. resources/js/routes and resources/js/actions are gitignored (.gitignore:18-19), so git will not surface the damage.

Validation: pest tests/Feature/DocumentProcessing + tests/Feature/Tenancy (216 passed); full backend suite (715 passed, 4962 assertions); vitest (310 passed, 50 files); 'sail npx tsc --noEmit' exit 0; pint passed on the three changed PHP files.

LanguageCoverageTest caught the two new __() permission strings as missing; added them to lang/en.json and lang/nl.json (nl: 'Verwerkingen opnieuw uitvoeren').
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added the pipelines.retry tenant permission and the PipelinePolicy::retry() gate that later tasks authorize against.

Changed: PipelinePermission gains a Retry case (label, description, PermissionGroup::Processing, defaultRoles Administrator + Adviser); PipelinePolicy gains retry(), which allows only PipelineStatus::Failed and Stuck combined with userHasPermissionInTenant. It deliberately avoids isTerminal() (used by the sibling delete()) because that would also admit completed and cancelled runs. Regenerated resources/js/types/generated/tenant-permissions.ts and app.d.ts, ran tenancy:sync-roles (1 permission created, 1 firm synced), and added the two new __() strings to lang/en.json and lang/nl.json.

Verified: new tests/Feature/DocumentProcessing/PipelinePolicyTest.php (11 tests) proves allow for owner/administrator/adviser on failed and stuck, deny for reviewer/read-only, deny for pending/running/completed/cancelled, and deny cross-tenant. The roles-matrix criterion was verified against the real page rather than from code: a pest --agent probe hit workspaces.roles.index and asserted 'pipelines.retry', 'Retry processing runs', and the processing group all render. Full backend suite 715 passed / 4962 assertions, vitest 310 passed, sail npx tsc --noEmit exit 0, pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
