---
id: TASK-31
title: Continue Pest 5 test modernization
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-10 22:11'
updated_date: '2026-08-10 22:15'
labels:
  - testing
  - pest
dependencies: []
references:
  - tests/Feature/Pipelines/PipelinePolicyTest.php
documentation:
  - docs/testing.md
  - .ai/rules/tests.md
priority: medium
type: chore
ordinal: 84000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Finish adopting Pest 5 conventions started in the testing-update commit: datasets for role/status matrices, describe() for transition/state suites, covers() on focused files, and workspace helpers instead of hand-rolled tenant setup. Follow .ai/rules/tests.md and docs/testing.md; PipelinePolicyTest is the exemplar.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Remaining transition/state suites that share setup use describe() groups where it improves readability
- [x] #2 Role/status matrices that only differ by inputs use named ->with() datasets; unique multi-step workflows stay as separate tests
- [x] #3 Hand-rolled tenant setup in transition Feature tests is replaced with provisionWorkspace()/workspace helpers
- [x] #4 Focused policy/transition/action/query/mapper tests that still lack covers() get them; broad HTTP/arch files stay without covers()
- [x] #5 Affected Pest tests pass via Sail
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Fix hand-rolled tenant setup in StateTransitionTest, UploadedDocumentProcessingTransitionsTest, AudioTranscriptionTransitionsTest, and DeleteUploadedDocumentActionTest — use provisionWorkspace()/actingInWorkspace().
2. Add describe() groups to those transition suites (and PipelineTest) matching DocumentRequestTransitionsTest / PipelineTransitionsTest style.
3. Add named ->with() datasets where inputs only differ: claim-refusal matrices, TenantRolePolicy actor matrix, ClientAccessGrant/TenantInvitation create roles, DocumentRequest completed-dossier denied abilities.
4. Skip covers() on broad HTTP/arch files; focused files already have covers from the prior commit.
5. Run affected Pest files via Sail; Pint dirty PHP if needed.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Sail --dirty Pint unavailable (no git in container mount); ran pint on the nine edited files instead.

Many non-transition Feature tests still hand-roll Tenant::factory()/makeCurrent(); left out of this batch per AC #3 scope.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Continued Pest 5 modernization after the testing-update commit.

- Grouped transition/state suites with describe() (StateTransitionTest, AudioTranscriptionTransitionsTest, UploadedDocumentProcessingTransitionsTest, PipelineTest).
- Replaced hand-rolled Tenant::factory()/makeCurrent() with provisionWorkspace() in those transition helpers plus DeleteUploadedDocumentActionTest.
- Added named ->with() datasets for claim-refusal matrices, TenantRolePolicy actor abilities, ClientAccessGrant/TenantInvitation create roles, and DocumentRequest completed-dossier denials.
- Left covers() as-is on focused files; skipped broad HTTP/arch suites.

Verified: sail artisan test --compact on the nine touched files — 55 passed (166 assertions).
<!-- SECTION:FINAL_SUMMARY:END -->
