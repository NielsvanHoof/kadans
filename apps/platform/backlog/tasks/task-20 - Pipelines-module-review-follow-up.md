---
id: TASK-20
title: Pipelines module review follow-up
status: Done
assignee: []
created_date: '2026-08-08 10:58'
updated_date: '2026-08-08 12:00'
labels: []
dependencies: []
priority: medium
type: chore
ordinal: 64000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
A full read of app/Pipelines/ and its call sites across DocumentProcessing, AudioProcessing, Dossiers and Reporting surfaced two correctness defects and a set of design weaknesses at the module's seams. The architecture itself is sound — transitions guard state legality, actions orchestrate, and the PipelineRetryRunner registry keeps per-domain knowledge out of Pipelines. The problems are: tenant scope bypassed by base-builder query termination, stringly-typed step keys at ~30 call sites, a 6-parameter generic action signature, per-step transaction/lock/broadcast overhead, per-subject knowledge that leaked back into Pipelines and Reporting, and dead columns/statuses exposed to the frontend. Subtasks carry the individual pieces.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Every subtask is Done
- [x] #2 vendor/bin/sail artisan test --compact passes for tests/Feature/Pipelines, tests/Feature/DocumentProcessing, tests/Feature/AudioRecordings and tests/Feature/Dossiers
<!-- AC:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
All seven subtasks are Done. Fixed two correctness defects (tenant scope bypassed by base-builder query termination, and AudioTranscription runs rendering a blank subject on the show page). Removed two whole classes of failure: step keys are now the PipelineStepKey enum instead of ~30 string literals, and PipelineState exposes named methods instead of a six-parameter generic handle(), so invalid operation/argument combinations are no longer representable. Inverted the last per-subject knowledge out of Pipelines behind a PipelineSubjectPresenter registry, mirroring the existing retry-runner pattern. Added PipelineState::run() so adjacent step transitions cost one lock, one transaction and one broadcast instead of one each. And rather than deleting the dead surface, implemented it: pipeline cancellation is reachable end to end, and next_retry_at drives real backoff-scheduled automatic retry. Standing constraints are recorded in .ai/rules/pipelines.md and .ai/rules/app.md. Full backend suite 871/876 with the 5 remaining failures confirmed pre-existing on develop; PHPStan at its 6-error baseline; frontend tests 363 passing.
<!-- SECTION:FINAL_SUMMARY:END -->
