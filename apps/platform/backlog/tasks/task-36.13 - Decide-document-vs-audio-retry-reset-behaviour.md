---
id: TASK-36.13
title: Decide document vs audio retry-reset behaviour
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:35'
updated_date: '2026-08-14 22:52'
labels:
  - audit
dependencies: []
references:
  - app/AudioProcessing/Jobs/ProcessAudioRecordingJob.php
  - app/DocumentProcessing/Jobs/ProcessUploadedDocumentJob.php
  - app/AudioProcessing/Transitions/AudioTranscriptionTransitions.php
  - app/DocumentProcessing/Transitions/UploadedDocumentProcessingTransitions.php
modified_files:
  - app/DocumentProcessing/Transitions/UploadedDocumentProcessingTransitions.php
  - app/DocumentProcessing/Jobs/ProcessUploadedDocumentJob.php
  - tests/Feature/DocumentProcessing/ProcessUploadedDocumentRetryTest.php
  - .ai/rules/jobs-transitions.md
parent_task_id: TASK-36
priority: low
type: spike
ordinal: 14000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
AudioTranscriptionTransitions::resetForRetry() is called from ProcessAudioRecordingJob on retry. UploadedDocumentProcessingTransitions has no equivalent; ProcessUploadedDocumentJob just rethrows. Decide which is right, or record the difference. Do not invent a shared pipeline to make them look the same.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 ProcessUploadedDocumentJob and ProcessAudioRecordingJob either share the same retry-reset behaviour or the difference is an explicit recorded decision
- [x] #2 No shared DocumentProcessing/AudioProcessing base class is introduced to reconcile them
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Spike resolved: this is a BUG, not a difference worth recording as intentional — so the two paths now match, and the reasoning is recorded instead.

Evidence, established before writing the fix. A test binds an OCR driver that reaches the provider (writing textract_job_id) and only then throws — the ordering matters, since a failure BEFORE the provider is reached leaves the job id null, which claim() already tolerates. Run against the unfixed code, the document is left on Processing with a job id rather than Pending. claim() reads 'Processing AND job id set' as an external job in flight and returns null, so every later attempt declined to claim and returned SUCCESSFULLY, meaning the exhausted-failure branch never ran: no processing_error, no DocumentProcessingFailed audit entry, and a review card spinning forever. Nothing recovers it — no scheduled OCR sweep exists, and StuckPipelinesCheck counts Pipeline rows, not processing_status.

Fix: UploadedDocumentProcessingTransitions::resetForRetry() mirroring AudioTranscriptionTransitions::resetForRetry() exactly — same minimal scope (status to Pending, provider job id nulled; it deliberately does NOT clear extracted_text/errors/timestamps, which is what OcrPipelineRunner::resetSubject() does for a manual pipeline retry), same path guard so a job holding a replaced upload revision cannot reset the current one. Called from ProcessUploadedDocumentJob's retry branch, matching the audio job line for line.

AC#2 respected: no shared base class. The duplication is per-domain and deliberate, per .ai/rules/document-processing-audio-processing.md.

Accepted cost, same as the audio path already accepts: the original provider job is orphaned and its completion no longer matches the new job id, so Textract is paid twice for that document. A duplicate charge beats a document that never finishes.

Recorded with Boost record-rule in .ai/rules/jobs-transitions.md, covering app/{DocumentProcessing,AudioProcessing}/{Jobs,Transitions}/** — it states the claim() interaction that makes the omission dangerous, the accepted cost, and the instruction not to reconcile the two domains with a shared abstraction.

Tests: tests/Feature/DocumentProcessing/ProcessUploadedDocumentRetryTest.php, covers(UploadedDocumentProcessingTransitions, ProcessUploadedDocumentJob) — the document is reclaimable after a post-submission failure, and the reset respects the path guard.

Verified: full suite 1235 tests / 6909 assertions; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
The two jobs now share the same retry-reset behaviour, because the difference turned out to be a bug rather than a design choice.

Proven before fixing: with a driver that reaches Textract and then fails, the document was left on Processing with a job id. claim() reads that as an external job in flight, so every later attempt declined to claim and returned successfully — the exhausted-failure branch never ran, leaving no error, no DocumentProcessingFailed entry, and a card that spins forever with nothing to recover it.

UploadedDocumentProcessingTransitions::resetForRetry() now mirrors the transcription one exactly, including its path guard and its minimal scope, and is called from the same place in the job. No shared base class was introduced; the duplication is deliberate per the domain rules.

The accepted cost — an orphaned provider job, so Textract is paid twice — is the same trade the audio path already made, and is recorded along with the claim() interaction via Boost record-rule in .ai/rules/jobs-transitions.md.

Verified: full suite 1235 tests / 6909 assertions; PHPStan level 8, Rector and Pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
