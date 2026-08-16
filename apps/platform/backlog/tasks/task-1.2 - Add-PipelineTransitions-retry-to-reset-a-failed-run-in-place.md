---
id: TASK-1.2
title: 'Add PipelineTransitions::retry() to reset a failed run in place'
status: Done
assignee:
  - '@claude'
created_date: '2026-08-05 21:39'
updated_date: '2026-08-05 22:08'
labels: []
dependencies: []
references:
  - app/DocumentProcessing/Transitions/PipelineTransitions.php
  - >-
    app/DocumentProcessing/Actions/Pipelines/ResolveProviderPipelineForSubjectAction.php
  - database/factories/PipelineFactory.php
documentation:
  - docs/backend.md
parent_task_id: TASK-1
type: feature
ordinal: 3000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Add the guarded lifecycle transition that resets a failed or stuck pipeline so it can be run again on the same row. This is the state-machine half of manual retry; the re-dispatch half is a separate task.

Add retry(Pipeline): Pipeline to app/DocumentProcessing/Transitions/PipelineTransitions.php, following the conventions already in that file: forceFill(...)->saveQuietly() for writes and ValidationException::withMessages for guards. Per docs/backend.md, Transitions are where guarded status rules belong, so the status guard lives here rather than in the calling Action.

Behaviour:
- Guard: reject anything that is not Failed or Stuck.
- Pipeline row: increment attempt_number, set status back to Pending, and clear current_stage, error_code, error_message, started_at, completed_at, failed_at, next_retry_at, and provider_job_id.
- Steps: reset every step to Pending and clear its error and timestamp columns, but preserve attempts so the count stays cumulative across retries. That cumulative count is what makes the step timeline useful on the show page. There are at most three steps per pipeline, so a simple loop matching the file's existing per-row saveQuietly() style is fine.

Clearing provider_job_id is load-bearing, not tidiness: ResolveProviderPipelineForSubjectAction prefers a pipeline whose provider_job_id matches an inbound provider result, so a stale Textract or Transcribe SQS message must not be able to match the retried run by its old job id.

Also add a failed() state to database/factories/PipelineFactory.php (status, error_code, error_message, failed_at) to keep this and later tests readable. The factory currently has only dossierExport().
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 retry() throws ValidationException unless the pipeline status is Failed or Stuck
- [x] #2 retry() increments attempt_number and sets status back to Pending
- [x] #3 retry() clears current_stage, error_code, error_message, started_at, completed_at, failed_at, next_retry_at, and provider_job_id on the pipeline
- [x] #4 Every step is reset to Pending with error_code, error_message, started_at, completed_at, failed_at, and last_activity_at cleared
- [x] #5 Step attempts values are preserved, not reset
- [x] #6 PipelineFactory gains a failed() state
- [x] #7 Tests in tests/Feature/DocumentProcessing/PipelineTransitionsTest.php cover the happy path from both failed and stuck, and rejection from completed, running, and cancelled
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add retry(Pipeline): Pipeline to PipelineTransitions, placed after stuck() since it is the inverse lifecycle move. Guard with in_array(status, [Failed, Stuck]) and ValidationException::withMessages(['pipeline' => 'Only a failed or stuck pipeline can be retried.']).
2. Reset steps first: loop $pipeline->steps()->get() and forceFill each to PipelineStepStatus::Pending with error_code, error_message, started_at, last_activity_at, completed_at, failed_at nulled. Do NOT touch attempts. saveQuietly() per row, matching the file's style (max 3 steps per pipeline).
3. Reset the pipeline row: status Pending, current_stage null, attempt_number + 1, provider_job_id null (so a stale Textract/Transcribe result cannot match this run via ResolveProviderPipelineForSubjectAction), error_code/error_message/started_at/next_retry_at/completed_at/failed_at null, last_activity_at now().
4. Add a realistic failed() state to PipelineFactory setting status, current_stage, provider_job_id, error_code, error_message, started_at, last_activity_at, failed_at — realistic enough that the reset assertions are meaningful rather than trivially null-to-null.
5. Add a describe('retry') block to tests/Feature/DocumentProcessing/PipelineTransitionsTest.php using it() and the file's existing pipelineWith()/stepFor() helpers (the file uses it() consistently, so follow it per docs/testing.md). Cover: reset from failed and from stuck; attempt_number increments; every listed column cleared; steps reset to pending; step attempts preserved; rejection from completed, running, cancelled, and pending.
6. Verify: pest on the transitions test then the full DocumentProcessing suite, pint on changed paths.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Placed retry() after stuck() in PipelineTransitions as the inverse lifecycle move. The guard runs before any write, so a rejected call mutates nothing — covered by an explicit 'leaves a rejected run completely untouched' test rather than assumed.

Made PipelineFactory::failed() realistic (current_stage, provider, provider_job_id, error_code, error_message, started_at, last_activity_at, failed_at) instead of just flipping status. Without that the reset assertions would have been trivially null-to-null and proved nothing.

Caught and fixed a vacuous assertion during review: 'expect($statuses)->each->toBe(Pending)' passes on an empty array, so a broken steps relation would have gone unnoticed. Added ->toHaveCount(3); assertion count rose 85 -> 86, confirming it actually executes.

A first verification probe failed and was my error, not the code's: I retried twice in a row and hit 'Only a failed or stuck pipeline can be retried.' That is the guard working correctly, since the first retry leaves the run Pending. Re-ran the realistic cycle instead (failed -> retry -> start -> failPipeline -> retry) and confirmed attempt_number 1->2->3, step attempts 7->8 (incremented by start(), preserved by retry()), status Pending, error_message cleared.

Validation: PipelineTransitionsTest 41 passed / 86 assertions; tests/Feature/DocumentProcessing 132 passed; full backend suite 726 passed / 4995 assertions; pint clean on the three changed files.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added PipelineTransitions::retry(), the state-machine half of manual retry: it reopens a failed or stuck run on the same row so it replays from its first step.

Changed: retry() guards on Failed|Stuck (throwing ValidationException otherwise, before any write), resets every step to Pending while clearing its error and timestamp columns, and resets the pipeline to Pending with attempt_number incremented and current_stage, error_code, error_message, started_at, next_retry_at, completed_at, failed_at, and provider_job_id cleared. Step attempts are deliberately preserved so the show-page timeline keeps a cumulative record. Dropping provider_job_id is load-bearing rather than cosmetic: it stops a late Textract or Transcribe result claiming the reopened run through ResolveProviderPipelineForSubjectAction. Also added a realistic PipelineFactory::failed() state.

Verified: 8 new tests in a describe('retry') block (41 passed / 86 assertions in the file) covering reset from both failed and stuck, every cleared column, provider_job_id dropped, all steps reset with attempts preserved, rejection from pending/running/completed/cancelled, and that a rejected call mutates nothing. A pest --agent probe additionally confirmed the realistic repeat cycle (failed -> retry -> start -> fail -> retry) yields attempt_number 3 with step attempts accumulating 7 -> 8. Full backend suite 726 passed / 4995 assertions; pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
