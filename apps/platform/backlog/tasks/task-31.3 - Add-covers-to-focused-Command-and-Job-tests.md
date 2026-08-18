---
id: TASK-31.3
title: Add covers() to focused Command and Job tests
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-10 22:29'
updated_date: '2026-08-10 22:30'
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
ordinal: 87000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Add covers() to focused Command/Job Feature tests so mutation and coverage scoping match the policy/transition/action pattern. Skip policy datasets (unique paths) and leave TenantMembershipPolicyTest alone.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Focused Command and Job Feature tests that target a single class declare covers(Class::class)
- [x] #2 UniqueJobLockExpiryTest either declares covers() for the three unique jobs it asserts or stays without covers with a short comment that it is cross-cutting
- [x] #3 Policy dataset work is explicitly out of scope
- [x] #4 Affected Pest files still pass via Sail
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add covers(Target::class) after imports on focused Command/Job tests: MonitorPipelines, RetryDuePipelines, ConsumeTextractResults, ProcessUploadedDocumentJob, ProcessAudioRecordingJob, GenerateQuestionnaireItemTypes, CreateOwner.
2. GrantSupportCommandTest covers GrantSupportCommand + RevokeSupportCommand (file tests both).
3. UniqueJobLockExpiryTest covers the three ShouldBeUnique jobs it asserts.
4. Do not touch policy datasets.
5. Sail pest on the touched files.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Skipped policy dataset work per user direction.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added covers() to focused Command/Job Feature tests.

- MonitorPipelinesCommand, RetryDuePipelinesCommand, ConsumeTextractResultsCommand, ProcessUploadedDocumentJob, ProcessAudioRecordingJob, GenerateQuestionnaireItemTypesCommand, CreateOwnerCommand
- GrantSupportCommandTest covers GrantSupportCommand + RevokeSupportCommand
- UniqueJobLockExpiryTest covers the three ShouldBeUnique jobs it asserts
- Policy datasets left alone (out of scope)

Verified: sail artisan test --compact on the nine files — 39 passed (189 assertions).
<!-- SECTION:FINAL_SUMMARY:END -->
