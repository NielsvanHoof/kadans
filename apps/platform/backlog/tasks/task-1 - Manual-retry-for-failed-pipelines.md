---
id: TASK-1
title: Manual retry for failed pipelines
status: Done
assignee: []
created_date: '2026-08-05 21:38'
updated_date: '2026-08-05 22:46'
labels: []
dependencies: []
documentation:
  - docs/architecture.md
  - docs/permissions.md
priority: high
type: feature
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
When a processing run fails, it is a dead end. The queue jobs (ProcessUploadedDocumentJob, ProcessAudioRecordingJob, GenerateDossierExportJob) each carry Tries(3) plus Backoff([10,30,60]); once those automatic attempts are exhausted, failed() calls PipelineStateAction and the pipeline lands on 'failed'. Separately, pipelines:monitor (every 5 min) flips inactive 'running' pipelines to 'stuck'. Neither state has any recovery path today, so a staff member must re-upload the document or re-request the export.

The schema was built for this and then left unwired: pipelines.attempt_number is written as 1 and never incremented, next_retry_at is never written at all, and the PipelineTransitions docblock already claims 'A pipeline may be retried from failed or stuck states' with no caller.

Goal: a Retry button on workspaces/pipelines/{pipeline} that re-queues a failed or stuck run in place, permission-gated and audit-logged.

Decisions taken up front:
- Reuse the existing pipeline row (increment attempt_number, reset steps, clear errors, re-dispatch) rather than creating a new run. Also required structurally: DossierExport::pipeline() is a MorphOne, so a second pipeline row per export would silently break it.
- Add a new pipelines.retry permission (Administrator + Adviser) and keep the failed/stuck status rule in the policy, mirroring how PipelinePolicy::delete() combines isTerminal() with PipelinePermission::Delete.

Scope: the show page only. Not adding retry to the pipeline index list, and not wiring next_retry_at or automatic re-dispatch; both stay dead.

Known risk that drives the design: both processing jobs' claimForProcessing() bail out silently (return null, do nothing) when the subject is Processing AND has a non-null provider job id, which is exactly the state a stuck run leaves behind. Retry must therefore reset the subject record, not just the pipeline row.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 An Administrator can retry a failed or stuck run from the pipeline show page and see it re-queue, with attempt_number incremented
- [x] #2 A user without pipelines.retry sees no Retry button and receives 403 from the endpoint
- [x] #3 Completed, running, and cancelled runs are not retryable
- [x] #4 Retry works for all three pipeline types: OCR document, audio transcription, and dossier export
<!-- AC:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Manual retry for failed pipelines is complete across all five subtasks.

A failed or stuck run can now be re-queued from workspaces/pipelines/{pipeline} by anyone holding the new pipelines.retry permission (Administrator, Adviser, and implicitly Owner). The run is reopened on its existing row — attempt_number increments, every step resets to pending, diagnostics clear — and the matching job is re-dispatched for all three pipeline types. The action is audit-logged as pipeline.retried and broadcast so open pages update live.

Delivered: PipelinePermission::Retry + PipelinePolicy::retry (1.1); PipelineTransitions::retry + PipelineFactory::failed (1.2); RetryPipelineAction + AuditEvent::PipelineRetried (1.3); the throttled POST endpoint, thin controller action and can_retry prop (1.4); the gated RetryPipelineButton component and live-reload wiring (1.5).

The load-bearing detail is the subject reset. Both processing jobs' claimForProcessing() skip silently when their subject is still 'processing' with a provider job id in flight — exactly what pipelines:monitor leaves behind when it marks a run stuck. Without resetting UploadedDocument / AudioRecording / DossierExport first, retry would report success and do nothing. That is guarded by a matched pair of tests: one proves a stuck-claimed document processes after retry, its twin proves the identical state is skipped without it.

Verified end to end, not just by unit tests: a pest --agent probe drove the real HTTP endpoint on a live sync queue and a document left processing with an abandoned textract_job_id came back completed with extracted text, pipeline failed -> completed, attempt_number 2. Final state: backend 748 passed / 5078 assertions, vitest 313 passed / 51 files, sail npx tsc --noEmit exit 0, biome clean, pint clean.

Not included, deliberately: retry from the pipeline index list, and next_retry_at / automatic re-dispatch — both remain unwired as scoped.
<!-- SECTION:FINAL_SUMMARY:END -->
