---
id: TASK-1.3
title: Add RetryPipelineAction to re-dispatch a failed run
status: Done
assignee:
  - '@claude'
created_date: '2026-08-05 21:39'
updated_date: '2026-08-05 22:20'
labels: []
dependencies:
  - TASK-1.2
references:
  - app/DocumentProcessing/Actions/Pipelines/DeletePipelineAction.php
  - app/DocumentProcessing/Actions/Pipelines/PipelineStateAction.php
  - app/Dossiers/Http/Controllers/DossierExportController.php
  - app/Audit/Enums/AuditEvent.php
parent_task_id: TASK-1
type: feature
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Add the Action that actually restarts a failed or stuck pipeline: reset the state, reset the subject record, log the audit event, and re-queue the right job for the pipeline type.

Create app/DocumentProcessing/Actions/Pipelines/RetryPipelineAction.php as a final readonly class with a single handle(Pipeline, User): Pipeline, modelled on the sibling DeletePipelineAction and on PipelineStateAction's locking. Constructor-inject PipelineTransitions, BroadcastPipelineUpdatedAction, and LogAuditActivityAction.

Inside a DB::transaction, lockForUpdate() the pipeline (same shape as PipelineStateAction::handle), capture the previous status, call the new transition, reset the subject, log audit, and dispatch. Broadcast after the transaction so open index and dossier pages see the flip back to pending.

Resetting the subject is the critical part and the reason this task exists separately from the transition. Both ProcessUploadedDocumentJob::claimForProcessing() and ProcessAudioRecordingJob::claimForProcessing() return null and silently do nothing when the subject is Processing AND carries a non-null provider job id, which is exactly the state a stuck run leaves behind. Without the reset, retry looks like it worked and then nothing happens. Reset by pipeline type via match on the pipelineable:
- OcrDocument / UploadedDocument: processing_status to Pending, and null out processing_error, extracted_text, textract_job_id, processing_started_at, processing_finished_at.
- AudioTranscription / AudioRecording: transcription_status to Pending, and null out transcription_error, transcript, segments, transcribe_job_id, transcription_started_at, transcription_finished_at.
- DossierExport / DossierExport: status to Pending, and null out error and failed_at. GenerateDossierExportAction already self-heals, but resetting keeps the export UI honest while it re-runs.

Dispatch the matching job with the existing pipeline id, using ->afterCommit() as DossierExportController already does: ProcessUploadedDocumentJob(doc id, doc path, pipeline id), ProcessAudioRecordingJob(recording id, recording path, pipeline id), or GenerateDossierExportJob(pipeline id).

Also add AuditEvent::PipelineRetried = 'pipeline.retried' next to PipelineDeleted, including its label match arm.

Layering note so this does not trip the architecture tests: App\DocumentProcessing may reference App\Dossiers (declared layer sibling in tests/Pest.php) and App\AudioProcessing (absent from every not->toUse() list), so importing those job and model classes directly is fine and no contract inversion is needed.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 RetryPipelineAction is final readonly with a single handle(Pipeline, User): Pipeline, and locks the pipeline inside a DB::transaction
- [x] #2 Delegates the status guard and state reset to PipelineTransitions::retry() rather than duplicating the rules
- [x] #3 Resets an UploadedDocument subject: processing_status to Pending and processing_error, extracted_text, textract_job_id, processing_started_at, processing_finished_at cleared
- [x] #4 Resets an AudioRecording subject: transcription_status to Pending and transcription_error, transcript, segments, transcribe_job_id, transcription_started_at, transcription_finished_at cleared
- [x] #5 Resets a DossierExport subject: status to Pending and error, failed_at cleared
- [x] #6 Dispatches the correct job per pipeline type with the existing pipeline id, using afterCommit()
- [x] #7 Regression test: an UploadedDocument left in the stuck shape (Processing with a non-null textract_job_id) is retried and the job actually processes it instead of being skipped by claimForProcessing()
- [x] #8 Writes AuditEvent::PipelineRetried with pipeline type, previous status, and the new attempt number, caused by the acting user
- [x] #9 Broadcasts PipelineUpdated after the transaction commits
- [x] #10 Architecture tests still pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add AuditEvent::PipelineRetried = 'pipeline.retried' next to PipelineDeleted, plus its label() arm, plus the __() string in lang/en.json and lang/nl.json (LanguageCoverageTest enforces this — it caught the equivalent omission in TASK-1.1).
2. Create app/DocumentProcessing/Actions/Pipelines/RetryPipelineAction.php, final readonly, handle(Pipeline, User): Pipeline. Inject PipelineTransitions, BroadcastPipelineUpdatedAction, LogAuditActivityAction.
3. Inside DB::transaction: lockForUpdate() the pipeline exactly as PipelineStateAction::handle does; capture previous status; call transitions->retry(); reset the subject; log AuditEvent::PipelineRetried with pipeline_type, previous_status, attempt_number, causer.
4. Subject reset via match($pipeline->type) on the loaded pipelineable, each guarded with an instanceof check:
   - OcrDocument / UploadedDocument: processing_status Pending; processing_error, extracted_text, textract_job_id, processing_started_at, processing_finished_at null.
   - AudioTranscription / AudioRecording: transcription_status Pending; transcription_error, transcript, segments, transcribe_job_id, transcription_started_at, transcription_finished_at null.
   - DossierExport / DossierExport: status Pending; error, failed_at null. Also clearing completed_at and expires_at — same field family, keeps a reopened row from advertising a stale archive. Leaving disk/path alone since GenerateDossierExportAction overwrites them on success and isDownloadable() already fails on a non-Completed status.
5. Guard a missing subject (pipelineable null or wrong class) with a ValidationException rather than dispatching a job that cannot work.
6. Dispatch per type with ->afterCommit(), matching DossierExportController: ProcessUploadedDocumentJob(id, path, pipelineId), ProcessAudioRecordingJob(id, path, pipelineId), GenerateDossierExportJob(pipelineId).
7. Broadcast after the transaction commits via BroadcastPipelineUpdatedAction.
8. Tests in a new tests/Feature/DocumentProcessing/RetryPipelineActionTest.php: per-type dispatch with Queue::fake(); per-type subject reset; the claimForProcessing regression (UploadedDocument left Processing with a non-null textract_job_id must actually be processed after retry, not skipped) driven by running the real job rather than asserting on columns; audit row written; broadcast fired; guard rejects a non-retryable status.
9. Verify: pest on the new file, then tests/Feature/DocumentProcessing, then the full backend suite; pint on changed paths; confirm architecture tests still pass (DocumentProcessing -> Dossiers/AudioProcessing imports).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Three deliberate decisions beyond the literal acceptance criteria, all small and defensive rather than new behaviour:

1. Dispatch moved OUTSIDE the DB::transaction (still with ->afterCommit()). Originally written inside it. The dominant codebase convention is to dispatch after the transaction block (UploadDocumentForRequestAction, UploadAudioRecordingAction); only DossierExportController uses afterCommit, and it does so outside a transaction too. Dispatching after the commit also guarantees a worker cannot claim the run while the subject reset is still uncommitted. handle() now returns [pipeline, subject] from the transaction so the dispatch and broadcast happen after it.

2. DossierExport reset also clears expires_at and completed_at, not just the status/error/failed_at named in AC #5. Same field family; stops a reopened row advertising a stale archive. disk and path are deliberately left alone — GenerateDossierExportAction overwrites them on success, and isDownloadable() already fails on any non-Completed status.

3. Added a missing-subject guard: resolveSubject() throws ValidationException when pipelineable is null or is not the class the pipeline type implies, rather than queueing a job that is guaranteed to no-op. Covered by a test that force-deletes the document.

Test design note: the claimForProcessing regression is a PAIR. 'lets the job process a document that a stuck run left claimed' asserts the document reaches Completed after retry; 'would be skipped without the reset' runs the identical starting state WITHOUT retrying and asserts it stays Processing. The second test is what proves the first is not vacuous.

Strongest evidence: a pest --agent probe with the real sync queue driver (no Queue::fake) drove the whole loop — a document left processing with textract_job_id 'abandoned' came out 'completed' and the pipeline went failed -> completed. That also confirms afterCommit() genuinely fires rather than silently swallowing the dispatch.

Validation: RetryPipelineActionTest 16 passed / 45 assertions; tests/Architecture 4 passed / 189 assertions (confirms DocumentProcessing -> Dossiers + AudioProcessing imports are permitted); full backend suite 742 passed / 5041 assertions; pint clean. LanguageCoverageTest again required the new __() string — added 'Pipeline retried' to lang/en.json and lang/nl.json.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added RetryPipelineAction, which re-queues a failed or stuck run on its existing pipeline row, plus AuditEvent::PipelineRetried.

Changed: the action locks the pipeline, delegates the status guard and state reset to PipelineTransitions::retry(), resets the subject record per pipeline type, writes an audit row, then dispatches the matching job and broadcasts once the transaction has committed. Subject reset covers UploadedDocument, AudioRecording, and DossierExport. A missing or mistyped subject is refused with a ValidationException rather than queueing a job that cannot work.

The subject reset is the substance of this task, not housekeeping: ProcessUploadedDocumentJob and ProcessAudioRecordingJob both skip silently when their subject is still 'processing' with a provider job id in flight, which is exactly the state pipelines:monitor leaves behind when it marks a run stuck. Without the reset a retry would report success and do nothing.

Verified: 16 tests / 45 assertions in tests/Feature/DocumentProcessing/RetryPipelineActionTest.php covering per-type dispatch, per-type subject reset, audit properties, broadcast, and refusal for pending/running/completed/cancelled plus a deleted subject. The regression is a matched pair — one test proves a stuck-claimed document processes after retry, its twin proves the identical state is skipped without it, so the assertion cannot pass vacuously. A pest --agent probe on the real sync queue then drove the full loop end to end: document processing/'abandoned' -> completed, pipeline failed -> completed, confirming afterCommit() actually fires. Architecture tests 4 passed / 189 assertions; full backend suite 742 passed / 5041 assertions; pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
