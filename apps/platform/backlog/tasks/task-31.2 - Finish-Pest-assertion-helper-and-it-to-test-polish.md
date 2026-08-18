---
id: TASK-31.2
title: 'Finish Pest assertion, helper, and it()-to-test polish'
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-10 22:26'
updated_date: '2026-08-10 22:27'
labels:
  - testing
  - pest
dependencies: []
documentation:
  - docs/testing.md
  - .ai/rules/tests.md
parent_task_id: TASK-31
priority: low
type: chore
ordinal: 86000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Finish the last Pest convention polish after TASK-31/31.1: semantic HTTP assertions, remaining workspace-helper nits, and it() to test() renames in files that only use it().
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Remaining assertStatus(429/400/302) in the listed throttle/validation/redirect tests use assertTooManyRequests/assertBadRequest/assertRedirect
- [x] #2 SharedInertiaDataTest multi-firm setup uses provisionWorkspace instead of ProvisionTenantAction
- [x] #3 Hand-rolled TenantMembership::query()->create in WorkspaceChannelAuthorizationTest, PlatformSupportTest, and ClientQuestionnaireCompletedNotificationTest use workspace helpers where they fit
- [x] #4 Listed it()-only files use test() instead; describe()+it() files like PipelineTransitionsTest and CancelPipelineActionTest are left alone
- [x] #5 Affected Pest tests pass via Sail
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Swap assertStatus(429/400/302) → assertTooManyRequests/assertBadRequest/assertRedirect in PasswordReset, ChecklistDraft, DossierReminderDraft, Client/Dossier IndexQuery.
2. SharedInertiaDataTest: provisionWorkspace for multi-firm + Tenant::forgetCurrent(); drop direct ProvisionTenantAction.
3. Memberships: workspaceMember (+ suspend update) for channel auth and portal notifications; keep PlatformSupport hand-roll with comment (must not assign Spatie role).
4. it()→test() in PermissionRegistry, SyncTenantRoles, DomainServiceProviders, OctaneConfiguration, LanguageCoverage, SecurityHeaders; leave CancelPipelineAction/PipelineTransitions alone.
5. Pint + Sail pest on touched files.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
PlatformSupport firm-member+support case intentionally skips workspaceMember because that helper assigns a Spatie role.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Finished the remaining Pest convention polish.

- Semantic HTTP assertions: assertTooManyRequests / assertBadRequest / assertRedirect in PasswordReset, ChecklistDraft, DossierReminderDraft, Client/Dossier IndexQuery (zero assertStatus left in the suite).
- SharedInertiaDataTest multi-firm setup now uses provisionWorkspace + Tenant::forgetCurrent().
- Memberships: workspaceMember (+ suspend update) in WorkspaceChannelAuthorizationTest and ClientQuestionnaireCompletedNotificationTest; PlatformSupportTest keeps a bare membership create with a comment (must not assign a Spatie role).
- it()→test() in PermissionRegistry, SyncTenantRoles, DomainServiceProviders, OctaneConfiguration, LanguageCoverage, SecurityHeaders; left CancelPipelineAction/PipelineTransitions on it() inside describe().

Verified: sail artisan test --compact on the touched files — 110 passed (618 assertions).
<!-- SECTION:FINAL_SUMMARY:END -->
