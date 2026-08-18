---
id: TASK-10.4
title: Unify the two processing jobs
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-06 09:54'
updated_date: '2026-08-06 22:20'
labels: []
dependencies:
  - TASK-10.3
references:
  - app/DocumentProcessing/Jobs/ProcessUploadedDocumentJob.php
  - app/AudioProcessing/Jobs/ProcessAudioRecordingJob.php
parent_task_id: TASK-10
priority: high
type: enhancement
ordinal: 38000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Final pair: the document processing job at 271 lines and the audio processing job at 297.

This is the most carefully written code in the pair and must not regress. The document job does a locking claim inside a transaction, checks for stale upload revisions by path, refuses to re-claim when a provider job is already in flight, and writes permanent failure as a conditional update that detects a stale revision and declines to mark it failed. All of that behaviour has to survive the extraction, and is generic rather than OCR-specific, so it should end up shared rather than duplicated.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 One job abstraction serves both providers
- [x] #2 The locking claim, stale-revision detection and in-flight refusal are preserved and shared
- [x] #3 The conditional permanent-failure write is preserved
- [x] #4 uniqueFor, retries, backoff, tags and the failed handler are preserved for both
- [x] #5 The existing eighteen DocumentProcessing feature tests pass untouched, and the duplicated AudioProcessing files are deleted
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Shared ProcessAsyncProviderSubject runner owns claim (locking + path + in-flight refusal), recordOutcome scaffolding hooks, and conditional permanent-failure write. Domain jobs stay as Laravel Job façades preserving uniqueFor/tries/backoff/tags/failed and provider-specific pipeline step graphs + notify.

AC#5: do not edit DocumentProcessing feature tests; delete any AudioProcessing files that become pure unused duplicates after extraction (keep thin ProcessAudioRecordingJob if still dispatched).

Run DocumentProcessing + AudioRecordings job tests; Pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
AC#5: DocumentProcessing tests not edited. ProcessAudioRecordingJob kept as dispatch façade (cannot delete without breaking queue/dispatch); duplicated claim/fail code deleted from both job files into Shared.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Extracted shared claim, permanent-failure write, and Process* job body into Shared. Both Laravel jobs remain thin façades preserving uniqueFor, retries, backoff, tags, and failed() handlers, plus provider-specific pipeline graphs and transcription notify/retry-reset behaviour.

## Shared
- `AsyncProviderSubjectClaimProfile`, `ClaimAsyncProviderSubject`
- `WriteAsyncProviderPermanentFailure`
- `ProcessAsyncProviderSubject`

## Domain
`ProcessUploadedDocumentJob` / `ProcessAudioRecordingJob` keep dispatch contracts; duplicated private claim/fail methods removed from both.

## AC#5 note
DocumentProcessing feature tests untouched and green. No entire AudioProcessing job file was deleted — both remain required queue entry points; duplicated logic inside them was removed.

## Tests
DocumentProcessing + AudioRecordings + RetryPipeline + Architecture — 134 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
