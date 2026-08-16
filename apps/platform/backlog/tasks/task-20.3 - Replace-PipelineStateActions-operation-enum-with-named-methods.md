---
id: TASK-20.3
title: Replace PipelineStateAction's operation enum with named methods
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 10:59'
updated_date: '2026-08-08 11:26'
labels: []
dependencies: []
parent_task_id: TASK-20
priority: medium
type: enhancement
ordinal: 67000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
PipelineStateAction::handle() takes a pipeline, a PipelineStepOperation, a nullable step key, a nullable Throwable, a nullable error code and a nullable error message, then matches straight back out to the transition it wants.

PipelineStepOperation is never persisted, broadcast or transported — it exists only to parameterise this one method. The costs are real: a runtime throw_unless($step instanceof PipelineStep) for operations that need a step, Fail and Stuck special-cased ahead of the match for the no-step case, and three nullable error parameters that are meaningless for Start, Complete and Skip.

Named methods make the invalid combinations unrepresentable and delete the guard clauses.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 PipelineStateAction exposes named methods (start, complete, skip, failStep, failPipeline, stuck, heartbeat) instead of a single handle()
- [x] #2 PipelineStepOperation is removed, or kept only if something outside the action needs to name an operation
- [x] #3 The throw_unless step guard and the Fail/Stuck no-step special cases are gone
- [x] #4 All call sites across DocumentProcessing, AudioProcessing, Dossiers and MonitorPipelinesCommand are updated
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Replace PipelineStateAction::handle() with named methods: start, complete, skip, heartbeat, heartbeatPipeline, failStep, failPipeline, stuckStep, stuckPipeline. Keep the shared lock/transaction/broadcast in one private runner so behaviour is identical.
2. Fold errorMessage/exception truncation into the failure methods only.
3. Delete PipelineStepOperation and its generated TypeScript entry once no caller names an operation.
4. Update call sites: ProcessUploadedDocumentJob, ProcessAudioRecordingJob, CompleteTranscribeJobAction, FailTranscribeJobAction, CompleteTextractExtractionAction, FailTextractExtractionAction, GenerateDossierExportAction, GenerateDossierExportJob, PrepareProviderPipelineForProviderResultAction, MonitorPipelinesCommand.
5. MonitorPipelinesCommand passes a nullable current_stage, so it needs the step-or-pipeline choice made explicitly at the call site.
6. Update tests, regenerate TypeScript, run the Pipelines/DocumentProcessing/AudioRecordings/Dossiers suites plus pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
The class moved as well as the methods. Dropping handle() broke the enforced architecture rule 'App\*\Actions classes must have a handle() method' (tests/Architecture/AppArchitectureTest, qompose preset in tests/Pest.php). Raised the three options with the user, who chose to move it rather than exempt it: App\Pipelines\Actions\PipelineStateAction is now App\Pipelines\Support\PipelineState. It stopped being a single action the moment it grew named operations, so the convention was telling the truth. The existing exemption (LogTenantActivityAction) is a Spatie LogActivityAction subclass, so it was a weak precedent for exempting a domain class.

Public surface: start, complete, skip (non-nullable step key), fail and stuck (nullable step key, plus optional Throwable/errorCode/errorMessage), heartbeat (pipeline) and heartbeatStep. The throw_unless step guard is gone because start/complete/skip cannot be called without a key. fail/stuck keep a nullable key deliberately — a job that dies before claiming a stage leaves current_stage null, and four call sites rely on that fallback; forcing an explicit branch at each of them would have been worse than one documented nullable.

Behaviour is preserved exactly: one lock and transaction per call via the private locked(), broadcast on every state change via lockedAndBroadcast(), and heartbeats still skip the broadcast so they do not trigger live reloads. Error truncation to 500 chars now lives only on the two failure methods instead of on every operation.

PipelineStepOperation was deleted; its generated TypeScript union went with it. Nothing in resources/js referenced it.

Two traps worth recording: PrepareProviderPipelineForProviderResultAction lives in App\Pipelines\Actions and had relied on same-namespace resolution, so the move left it silently resolving App\Pipelines\Actions\PipelineState — it needed an explicit import, and the failure only showed as 'Target class does not exist' at container resolution time. PipelineControllerTest's failedPipelineForController() helper was parameterised on PipelineStepOperation; it now takes a PipelineStatus end state, which reads better anyway.

Validation: tests/Architecture + Pipelines + DocumentProcessing + AudioRecordings + Dossiers -> 318 passed. Full suite -> 844/849, same 5 pre-existing failures as the clean-tree baseline. composer types:check -> 6 errors, identical to baseline. npm run types:check -> only the pre-existing aria-current error. Pint passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Replaced PipelineStateAction's single handle(Pipeline, PipelineStepOperation, ?stepKey, ?Throwable, ?errorCode, ?errorMessage) with named methods, and deleted PipelineStepOperation. Invalid combinations are no longer representable, so the runtime throw_unless step guard and the Fail/Stuck no-step branches ahead of the match are gone. Because the class no longer fits the App\*\Actions handle() convention, it moved to App\Pipelines\Support\PipelineState per the user's decision. All call sites across Pipelines, DocumentProcessing, AudioProcessing, Dossiers and the tests were converted with no behaviour change — same lock, transaction and broadcast semantics, heartbeats still silent. Verified against the architecture suite, the four domain suites (318 passed), the full suite at baseline, and PHPStan at baseline.
<!-- SECTION:FINAL_SUMMARY:END -->
