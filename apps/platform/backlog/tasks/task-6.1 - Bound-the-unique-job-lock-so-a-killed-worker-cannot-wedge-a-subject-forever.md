---
id: TASK-6.1
title: Bound the unique-job lock so a killed worker cannot wedge a subject forever
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 13:59'
labels: []
dependencies: []
references:
  - app/DocumentProcessing/Jobs/ProcessUploadedDocumentJob.php
  - app/AudioProcessing/Jobs/ProcessAudioRecordingJob.php
  - app/Dossiers/Jobs/GenerateDossierExportJob.php
modified_files:
  - app/DocumentProcessing/Jobs/ProcessUploadedDocumentJob.php
  - app/AudioProcessing/Jobs/ProcessAudioRecordingJob.php
  - app/Dossiers/Jobs/GenerateDossierExportJob.php
  - tests/Feature/Queue/UniqueJobLockExpiryTest.php
parent_task_id: TASK-6
priority: high
type: bug
ordinal: 22000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
All three queued jobs - document OCR, audio transcription and dossier export - implement ShouldBeUnique with a uniqueId, but `uniqueFor` appears nowhere in the application. The default of zero means the lock is released only by normal completion or failure.

On an out-of-memory kill, a SIGKILL or a container eviction the lock is never released. Every subsequent dispatch for that subject is then silently dropped, so the upload sits in a processing state forever with no error surfaced to the user and nothing in the failed jobs table.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 All three jobs declare a uniqueFor window derived from their timeout and retry count
- [x] #2 After the window elapses a new dispatch for the same subject is accepted and processes it
- [x] #3 A test asserts a dispatch is accepted once the lock has expired
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Laravel 13 UniqueLock reads uniqueFor() method or #[UniqueFor] attribute only (not $uniqueFor property).
2. Add uniqueFor() on ProcessUploadedDocumentJob, ProcessAudioRecordingJob, GenerateDossierExportJob.
3. Formula: (job timeout × tries) + sum(backoff [10,30,60]). Audio uses $this->timeout from config; document/export declare explicit $timeout aligned with worker (default 60 unless config exists).
4. Extract shared BACKOFF constant or reuse attribute values for the sum.
5. Feature test: acquire UniqueLock with short uniqueFor (or travel time), assert second dispatch blocked then accepted after expiry via Bus::fake / UniqueLock + Cache::lock.
6. Pint + sail test.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Laravel 13 UniqueLock ignores public $uniqueFor; used uniqueFor() method. Array cache locks use wall-clock time(), so the expiry test sleeps 2s after seeding a 1s lock.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Bounded ShouldBeUnique locks on ProcessUploadedDocumentJob, ProcessAudioRecordingJob, and GenerateDossierExportJob so a killed worker cannot wedge a subject forever.

## Changes
- Added `uniqueFor()` on all three jobs: `(timeout × MAX_TRIES) + sum(BACKOFF_SECONDS)`.
- Laravel 13 UniqueLock only honors `uniqueFor()` / `#[UniqueFor]`, not `$uniqueFor`.
- Document and export jobs now declare `$timeout = 120` and shared `BACKOFF_SECONDS` / `MAX_TRIES` constants.

## Tests
`tests/Feature/Queue/UniqueJobLockExpiryTest.php` — formula assertions for all three jobs; abandoned short-TTL lock blocks dispatch then allows it after expiry.
Also re-ran related job/tag tests (20 passed).
<!-- SECTION:FINAL_SUMMARY:END -->
