---
id: TASK-9.3
title: Add AudioProcessing to the enforced layer order
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:53'
updated_date: '2026-08-06 16:13'
labels: []
dependencies:
  - TASK-9.1
  - TASK-9.2
references:
  - tests/Pest.php
documentation:
  - docs/architecture.md
parent_task_id: TASK-9
priority: high
type: enhancement
ordinal: 32000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Place AudioProcessing at layer four alongside DocumentProcessing in both the architecture doc and the arch test, and add it to every existing not-to-use list including the Accounts constraint that is currently missing.

The arch test going red on the two mutual cycles before the preceding subtasks land is the expected intermediate state and is the verification that the rule actually bites. This task is done when it is green again without an ignoring escape hatch.

If a remaining edge turns out to be genuinely mutual - as Clients and Dossiers are, and Dossiers and DocumentProcessing are - then document it as a deliberate layer sibling in the same honest style as the existing comments, rather than suppressing it.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 AudioProcessing appears at layer four in docs/architecture.md
- [x] #2 AudioProcessing has its own not-to-use rule and appears in every other domain not-to-use list, including Accounts
- [x] #3 The arch tests pass with no ignoring entry added for AudioProcessing
- [x] #4 Any edge kept as a deliberate layer sibling is justified in a comment explaining why the dependency is genuinely mutual
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add AudioProcessing to L4 in docs/architecture.md alongside DocumentProcessing; document deliberate siblings (AudioProcessing with DocumentProcessing and/or Dossiers) if mutual edges remain.
2. Update Pest.php domain layering: AudioProcessing not-to-use rule; add AudioProcessing to every other domain's forbidden list including Accounts.
3. Run arch tests; fix any unexpected violations without ignore escape hatches.
4. Comment any kept mutual edges honestly.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Removed assert() from the three PipelineRetryRunner implementations (security preset forbids assert); replaced with throw_unless + InvalidArgumentException. Runners live under */Retry/, not Actions/, so the qompose *Action handle() rule does not apply.

Verified: vendor/bin/sail artisan test --compact tests/Architecture/AppArchitectureTest.php tests/Feature/DocumentProcessing/RetryPipelineActionTest.php → 20 passed. No App\AudioProcessing ignore in AppArchitectureTest.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
- AudioProcessing is L4 in docs/architecture.md and Pest.php domain layering (own not-to-use rule; listed on Shared/Accounts/User/Tenancy/Audit/Questionnaires/ClientPortal; siblings with Clients/Dossiers/DocumentProcessing).
- Deliberate mutual edges documented: Dossiers↔AudioProcessing, AudioProcessing↔DocumentProcessing (plus existing Clients/Dossiers and Dossiers/DocumentProcessing).
- Cleared remaining arch failures by moving retry runners to Retry/ and replacing assert() with throw_unless.

## Tests
- AppArchitectureTest + RetryPipelineActionTest: 20 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
