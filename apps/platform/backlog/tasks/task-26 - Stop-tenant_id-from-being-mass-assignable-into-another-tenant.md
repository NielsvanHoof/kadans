---
id: TASK-26
title: Stop tenant_id from being mass-assignable into another tenant
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-08 13:35'
updated_date: '2026-08-11 08:51'
labels: []
milestone: m-0
dependencies: []
references:
  - app/Tenancy/Concerns/BelongsToTenant.php
  - app/Tenancy/Models/TenantOwnedModel.php
documentation:
  - docs/security.md
modified_files:
  - app/Tenancy/Concerns/BelongsToTenant.php
  - database/factories/ClientFactory.php
  - database/factories/TenantInvitationFactory.php
  - tests/Feature/Tenancy/BelongsToTenantScopeTest.php
  - tests/Feature/Portal/PortalUploadedDocumentDestroyTest.php
  - tests/Feature/DocumentProcessing/DocumentExtractionPageTest.php
  - tests/Feature/Pipelines/DeletePipelinesForSubjectsActionTest.php
  - tests/Feature/Pipelines/MonitorPipelinesCommandTest.php
  - docs/security.md
priority: medium
type: enhancement
ordinal: 79000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Thirteen of the fourteen tenant-owned models list `tenant_id` in `$fillable`, and `BelongsToTenant::bootBelongsToTenant()` only stamps the current tenant when the attribute is *not already set*. Together that means any code path which mass-assigns a caller-supplied `tenant_id` writes a record into another firm.

This is not exploitable today, and the audit that found it confirms why: every controller passes `$request->validated()` rather than `->all()`, and no `rules()` array in the application contains `tenant_id`, so an unvalidated key never reaches `create()` or `update()`. The risk is latent, not present. It is worth closing anyway because tenant isolation is the entire security model of this platform, and the distance between safe and unsafe here is one added validation rule or one `->update($request->all())` in a future action — a mistake with no local symptom, since the write succeeds and the record simply becomes invisible to the tenant that created it.

The trap for the implementer: factories and seeders mass-assign `tenant_id` deliberately, including to build cross-tenant fixtures that several isolation tests depend on. Removing the attribute from `$fillable` will break the suite. A guard that rejects a *dirty* `tenant_id` differing from the current tenant is likelier to work, with deliberate cross-tenant fixtures constructed inside `$otherTenant->execute()` — but confirm that against the existing tests before committing to the approach, and record the chosen mechanism in the task notes.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A write that supplies a tenant_id other than the current tenant fails loudly instead of persisting a cross-tenant record
- [x] #2 Creating a tenant-owned record with no tenant_id still stamps the current tenant as before
- [x] #3 Existing cross-tenant test fixtures still build, and the full suite passes
- [x] #4 A regression test covers the smuggled-tenant_id case
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Mechanism
Keep `tenant_id` in `$fillable` (factories/seeders need it). Harden `BelongsToTenant::bootBelongsToTenant()`:

1. **creating** — if `tenant_id` is absent/null, stamp `Tenant::current()` (existing behaviour; still throws with no current tenant). If `tenant_id` is set and a current tenant exists, require they match (string-cast compare); otherwise throw `RuntimeException`. If no current tenant but `tenant_id` is set, allow (factory nesting `Tenant::factory()`).
2. **updating** — if `tenant_id` is dirty, throw (immutable after create).

## Tests
- Extend `BelongsToTenantScopeTest` (or adjacent Feature test): smuggled `tenant_id` throws; create without `tenant_id` stamps current; matching explicit `tenant_id` still works.
- Fix any fixture that creates for tenant A while current is B (notably `PortalUploadedDocumentDestroyTest`) by `actingInWorkspace()` before create.

## Docs
Brief note under Tenant isolation in `docs/security.md`.

## Verify
Focused BelongsToTenant + Portal destroy tests, then broader Feature/Tenancy + a wider slice if needed.

## Factory follow-up
Nested `Pipeline::factory()` → Client used `Tenant::factory()`, which stamped a foreign tenant_id under an active workspace and tripped the new guard. `ClientFactory` / `TenantInvitationFactory` now use `Tenant::current()?->getKey() ?? Tenant::factory()`.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Chose BelongsToTenant creating/updating guards (keep tenant_id fillable). ClientFactory and TenantInvitationFactory now prefer Tenant::current() so nested Pipeline→…→Client factories stay in-tenant. Fixed PortalUploadedDocumentDestroyTest and DocumentExtractionPageTest to actingInWorkspace before home-tenant fixtures.

Full suite green (1052). Recorded .ai/rules note for BelongsToTenant write guard + current-tenant factories.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Closed the latent cross-tenant mass-assignment hole without removing `tenant_id` from `$fillable`.

- `BelongsToTenant`: on create, stamp current tenant when absent; throw if a supplied `tenant_id` differs from `Tenant::current()`; on update, reject dirty `tenant_id`.
- `ClientFactory` / `TenantInvitationFactory`: prefer `Tenant::current()` so nested factories stay in-tenant.
- Test fixture fixes: `PortalUploadedDocumentDestroyTest`, `DocumentExtractionPageTest`, pipeline helpers (`subjectWithPipeline`, `monitoredPipeline`) switch workspace before creating for a specific tenant.
- Documented under Tenant isolation in `docs/security.md`; recorded shared rule in `.ai/rules`.

## Tests
- New cases in `BelongsToTenantScopeTest` (stamp / match / smuggle / immutable)
- Full suite: `vendor/bin/sail artisan test --compact` — **1052 passed**
<!-- SECTION:FINAL_SUMMARY:END -->
