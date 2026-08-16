---
id: TASK-20.6
title: Batch pipeline step writes into one transaction and one broadcast
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 10:59'
updated_date: '2026-08-08 11:42'
labels: []
dependencies: []
parent_task_id: TASK-20
priority: low
type: enhancement
ordinal: 70000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Completing an audio run is five separate PipelineStateAction calls in ProcessAudioRecordingJob, and PrepareProviderPipelineForProviderResultAction adds more. Each opens its own transaction (a savepoint when nested, as in CompleteTranscribeJobAction), takes lockForUpdate on the same pipeline row, and fires a PipelineUpdated broadcast.

One logical 'finish the run' therefore costs about five lock and commit cycles and queues about five broadcast jobs that the UI collapses into a single visible change.

A scoped batch API would lock once, apply the steps in order and broadcast once at the end. Broadcast coalescing alone is the lower-risk fallback if the new API is not wanted. This is the largest change in the review and should land after the others.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A scoped batch API applies an ordered run of step transitions under one lock and one transaction
- [x] #2 A logical run completion emits exactly one PipelineUpdated, asserted with Event::fake()
- [ ] #3 ProcessAudioRecordingJob, ProcessUploadedDocumentJob, GenerateDossierExportAction and PrepareProviderPipelineForProviderResultAction use it
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add PipelineState::run(Pipeline, callable): apply an ordered set of step transitions under ONE pipeline lock, ONE transaction and ONE broadcast at the end.
2. The callback receives a small fluent recorder exposing start/complete/skip/fail/stuck/heartbeat, each locking its step within the already-held pipeline lock and returning $this.
3. Keep the existing single-operation methods; they become run() with a single instruction so behaviour and semantics stay identical.
4. Convert the multi-call sequences: ProcessAudioRecordingJob::recordOutcome (5 calls), ProcessUploadedDocumentJob::recordOutcome (4), GenerateDossierExportAction (4), CompleteTranscribeJobAction (3 sites), CompleteTextractExtractionAction (3 sites), PrepareProviderPipelineForProviderResultAction.
5. Assert with Event::fake() that one logical run completion emits exactly one PipelineUpdated.
6. Run the affected suites, types:check and pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
AC3 is only partly done, on purpose. GenerateDossierExportAction is deliberately NOT batched: it interleaves real work with its transitions — start(build_archive), build the ZIP, complete(build_archive), start(store_archive), upload to S3, complete(store_archive). Wrapping that in one transaction would hold lockForUpdate on the pipeline row across an archive build and an object-store upload, which is far worse than the extra broadcasts, and it would destroy the point of start(): observers seeing each stage begin while it runs. Same reasoning applies to the second half of CompleteTranscribeJobAction and CompleteTextractExtractionAction, where complete(process_results) sits after the extraction work and stays a single call.

Batched where the transitions really are adjacent bookkeeping: ProcessAudioRecordingJob::recordOutcome (5 calls -> 1, plus the deferred branch 2 -> 1), ProcessUploadedDocumentJob::recordOutcome (4 -> 1, deferred branch 2 -> 1), CompleteTranscribeJobAction (both catch-up sites), CompleteTextractExtractionAction (both catch-up sites), and PrepareProviderPipelineForProviderResultAction (up to 3 -> 1).

The API is PipelineState::run(Pipeline, callable) handing a PipelineStepSequence to the callback. The sequence exposes only start/complete/skip: a failure ends a sequence rather than continuing it, and heartbeats do not broadcast, so fail/stuck/heartbeat stay single operations on PipelineState. It reaches lockStep via a Closure so that method stays private. The existing single-operation methods are unchanged, so nothing else shifted.

One deliberate semantic change worth knowing: a batched sequence is one transaction, so a mid-sequence illegal transition now rolls the whole run back instead of leaving it half-advanced across several committed transactions. Covered by the new 'rolls every step back together' test, which also asserts no broadcast fires on the failed path.

PrepareProviderPipelineForProviderResultAction needed care in two places. Its provider-step status is now read before the submit catch-up rather than after — equivalent, because completing a step moves the pipeline's status and current_stage, never another step's status, and that reasoning is in a comment. And it early-returns when there is nothing to catch up, so the no-op case still performs no write and fires no broadcast, exactly as before.

Validation: tests/Feature/Pipelines/PipelineUpdatedBroadcastTest -> 6 passed (2 new). Pipelines + DocumentProcessing + AudioRecordings + Dossiers -> 319 passed. Full suite -> 851/856, same 5 pre-existing failures as baseline. composer types:check -> 6 errors, back to baseline after typing the callback return as mixed so the fluent arrow functions type-check. npm run types:check -> 1 error, the pre-existing aria-current one. Pint passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added PipelineState::run() plus PipelineStepSequence so adjacent step transitions apply under one lock, one transaction and one broadcast instead of one each. Converted the six sequences that are genuinely adjacent bookkeeping — the largest, ProcessAudioRecordingJob::recordOutcome, went from five lock/commit/broadcast cycles to one. Deliberately left GenerateDossierExportAction and the post-extraction completions unbatched, since they interleave archive builds, uploads and extraction with their transitions and must not hold the row lock across that work; that decision is recorded in the notes and in the run() docblock. A batched sequence is now atomic, so a mid-sequence illegal transition rolls back rather than leaving a half-advanced run. Verified with two new tests (exactly one PipelineUpdated for five transitions; full rollback and no broadcast on failure), the four domain suites, the full suite at baseline, and PHPStan at baseline.
<!-- SECTION:FINAL_SUMMARY:END -->
