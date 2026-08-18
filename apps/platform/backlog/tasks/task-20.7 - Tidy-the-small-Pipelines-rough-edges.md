---
id: TASK-20.7
title: Tidy the small Pipelines rough edges
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 10:59'
updated_date: '2026-08-08 11:35'
labels: []
dependencies: []
parent_task_id: TASK-20
priority: low
type: chore
ordinal: 71000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Leftovers from the module review that are too small to carry their own task.

PipelineTransitions is declared final while the stateless PipelineStepTransitions beside it is final readonly.

PipelineStepTransitions::advance() runs a query per completed step to find the next pending one, even though the steps relation is already loaded on most call paths.

No .ai/rules file covers app/Pipelines/**, despite the module having real standing constraints: writes are quiet because the models do not use LogsActivity, Pipelines must not import domain models (register through the runner and presenter registries instead), and transitions guard state legality while actions orchestrate.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 PipelineTransitions is final readonly
- [ ] #2 advance() uses the loaded steps relation rather than a query per completed step
- [x] #3 A rule covering app/Pipelines/** is recorded via record-rule and listed in .ai/rules/index.md
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Make PipelineTransitions final readonly (it holds no state).
2. Replace PipelineStepTransitions::advance()'s per-completion query with a lookup over the loaded steps relation, being careful that the just-written step status is reflected (the relation may be stale after a forceFill/saveQuietly).
3. Record a rule for app/Pipelines/** via record-rule covering: quiet writes and why, no domain imports (register through the runner/presenter registries), transitions guard while actions orchestrate, and PipelineState as the single writer.
4. Run the Pipelines suite plus pint and types:check.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
AC2 (advance() should use the loaded steps relation) is deliberately NOT done — the finding it came from does not survive contact with the code, and acting on it would have added risk for no gain.

Two things were wrong in the original review note. First, advance() does not run per completed step in an N+1 sense: it runs once per complete()/skip() call, so the query count is proportional to the number of step transitions actually being performed, not multiplied by anything. Second, the claim that 'the steps relation is already loaded on most call paths' is false on the hot path — PipelineState hands transitions a $lockedPipeline it fetched with Pipeline::query()->lockForUpdate()->firstOrFail(), which has no relations loaded. Switching to the relation would therefore have added a load, not removed a query, and it would have introduced a real staleness bug: the step that just completed was written via forceFill/saveQuietly on a different instance, so a relation loaded before that write would still show it Pending and advance() would pick the wrong next step.

Indexing is fine too: pipeline_steps carries unique(['pipeline_id', 'key']), whose leading column narrows to the 2-3 steps of one run before the status filter and position sort, and that same index serves PipelineState::lockStep(). No index gap to close.

AC1: PipelineTransitions is now final readonly, matching the stateless PipelineStepTransitions beside it.
AC3: rule recorded via record-rule into .ai/rules/pipelines.md, with the app/Pipelines/** row added to .ai/rules/index.md. It covers PipelineState as the single writer and why it lives in Support rather than Actions, the transitions-guard/actions-orchestrate split and its role in callback idempotency, why every write is quiet, and the no-domain-imports rule with both registries plus the one documented PipelinePolicy exception.

Validation: tests/Feature/Pipelines -> 89 passed. Pint passed. composer types:check -> 6 errors, identical to the clean-tree baseline.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Made PipelineTransitions final readonly and recorded the Pipelines module's standing constraints in .ai/rules/pipelines.md (single writer, guarded transitions, quiet writes, no domain imports via the runner and presenter registries). Withdrew the advance() optimisation: re-reading the code showed it is not an N+1, the steps relation is not loaded on the hot path so the change would add a query rather than remove one, and reading step status from a relation loaded before the completing write would pick the wrong next step. The existing unique(['pipeline_id','key']) index already covers both advance() and lockStep(). AC2 is left unchecked with that reasoning rather than being quietly reworded.
<!-- SECTION:FINAL_SUMMARY:END -->
