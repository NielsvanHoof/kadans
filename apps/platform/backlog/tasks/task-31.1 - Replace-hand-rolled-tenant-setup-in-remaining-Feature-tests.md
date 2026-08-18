---
id: TASK-31.1
title: Replace hand-rolled tenant setup in remaining Feature tests
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-10 22:19'
updated_date: '2026-08-10 22:22'
labels:
  - testing
  - pest
  - tenancy
dependencies: []
references:
  - tests/Feature/Portal/ClientPortalTest.php
  - tests/Feature/DocumentProcessing/ProcessUploadedDocumentJobTest.php
documentation:
  - docs/testing.md
  - .ai/rules/tests.md
  - tests/Pest.php
parent_task_id: TASK-31
priority: medium
type: chore
ordinal: 85000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Follow-up to TASK-31: remaining non-transition Feature tests still hand-roll Tenant::factory()/makeCurrent() or re-call makeCurrent() after helpers already set the current tenant. Align them with the mandatory workspace helpers in tests/Pest.php and .ai/rules/tests.md so tenant context stays consistent.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Feature tests that need a workspace tenant use provisionWorkspace()/actingInWorkspace() instead of Tenant::factory()->create() + makeCurrent()
- [x] #2 Redundant $tenant->makeCurrent() / context tenant makeCurrent() calls after helpers already set the current tenant are removed or replaced with actingInWorkspace()
- [x] #3 Intentional tenancy-subject tests (TenantContextTest, BelongsToTenantScopeTest, TenantProvisioningTest) keep only the direct tenant calls required to exercise the code under test, with a short comment if direct use remains
- [x] #4 rg for hand-rolled Tenant::factory()->create()/makeCurrent() in tests/Feature shows only justified exceptions
- [x] #5 Affected Pest files pass via Sail
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. DocumentProcessing + AudioRecordings: replace Tenant::factory()->create() (+ makeCurrent) helpers with provisionWorkspace()/actingInWorkspace(); for two-tenant isolation use two provisionWorkspace() calls.
2. Portal/Dossiers/Clients/Questionnaires/Retention/Reporting: replace $tenant->makeCurrent() and $context['tenant']->makeCurrent() with actingInWorkspace(...).
3. Tenancy exceptions: keep TenantContextTest makeCurrent (subject under test); keep Tenant::factory in TenantProvisioningTest (slug collision) and BelongsToTenantScopeTest runForTenant fixture; add brief comments.
4. rg audit remaining hand-rolls; Pint listed files; run affected Pest suites via Sail.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Dashboard firm-picker needed Tenant::forgetCurrent() after provisionWorkspace because DashboardController::index redirects when Tenant::current() is set.

Subagents handled the bulk DocumentProcessing/Audio and Portal/Dossiers replacements; parent verified the full suite.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Migrated remaining Feature tests off hand-rolled tenant setup onto provisionWorkspace()/actingInWorkspace().

- DocumentProcessing + AudioRecordings helpers/tests: Tenant::factory()+makeCurrent → provisionWorkspace (incl. two-tenant Firm A/B isolation).
- Portal/Dossiers/Clients/Questionnaires/Retention/Reporting: ~43 makeCurrent calls → actingInWorkspace.
- Dashboard multi-firm chooser now provisions three firms for one user, suspends one membership, then Tenant::forgetCurrent() so the picker renders (controller redirects when a current tenant is set).
- Annotated intentional exceptions: TenantContextTest, BelongsToTenantScopeTest runForTenant fixture, TenantProvisioningTest slug-reservation factory rows.

Verified: sail artisan test --compact across the affected suites — 268 passed (1526 assertions). rg shows Tenant::factory/makeCurrent only in the three justified Tenancy exception files.
<!-- SECTION:FINAL_SUMMARY:END -->
