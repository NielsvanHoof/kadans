---
id: TASK-9.1
title: Move TranscriptionStatus into the AudioProcessing domain
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:53'
updated_date: '2026-08-06 16:08'
labels: []
dependencies: []
references:
  - app/DocumentProcessing/Enums/TranscriptionStatus.php
  - app/AudioProcessing
  - app/Dossiers/Models/AudioRecording.php
modified_files:
  - app/AudioProcessing/Enums/TranscriptionStatus.php
  - app/DocumentProcessing/Enums/TranscriptionStatus.php
  - resources/js/features/audio-recordings/types.ts
  - resources/js/types/generated/app.d.ts
  - app/DocumentProcessing/Jobs/ProcessUploadedDocumentJob.php
  - app/Dossiers/Jobs/GenerateDossierExportJob.php
  - tests/Feature/Queue/UniqueJobLockExpiryTest.php
parent_task_id: TASK-9
priority: high
type: enhancement
ordinal: 30000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The TranscriptionStatus enum lives in the DocumentProcessing enums folder, next to the OCR status enum, despite being audio transcription state. It is imported by seven AudioProcessing files plus the audio recording model and its staff DTO.

Those seven imports are roughly a third of the entire AudioProcessing to DocumentProcessing edge count, so moving one file removes a third of the coupling. The stored values and cast strings do not change, so this is a namespace move only.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 TranscriptionStatus lives in the AudioProcessing enums namespace
- [x] #2 No AudioProcessing file imports it from DocumentProcessing any more
- [x] #3 Stored database values and any generated TypeScript enum are unchanged
- [x] #4 The full test suite passes without touching test expectations for status values
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Create App\AudioProcessing\Enums\TranscriptionStatus (same cases/values/label).
2. Delete DocumentProcessing enum; update all PHP imports.
3. Regenerate TypeScript types; update resources/js/features/audio-recordings/types.ts alias.
4. Run focused audio + retry tests.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Moved TranscriptionStatus into App\AudioProcessing\Enums (namespace-only; values unchanged).

## Changes
- New AudioProcessing enum; removed DocumentProcessing copy.
- Updated PHP imports and TS alias; regenerated app.d.ts.
- Fixed uniqueFor() on OCR/export jobs to use TIMEOUT_SECONDS (#[Timeout] left $this->timeout undefined).

## Tests
AudioRecordings + RetryPipelineActionTest + UniqueJobLockExpiryTest: 51 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
