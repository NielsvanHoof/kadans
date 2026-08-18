---
id: TASK-35.2
title: Delete audio recordings from object storage when a dossier is legally purged
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-13 11:33'
updated_date: '2026-08-13 12:05'
labels:
  - audit
  - gdpr
dependencies: []
references:
  - app/Platform/Actions/Retention/PurgeExpiredDossierAction.php
  - app/Platform/Actions/Retention/DeleteStoredDocumentFilesAction.php
  - database/migrations/2026_08_03_090000_create_audio_recordings_table.php
  - app/Platform/Actions/Privacy/GenerateDataSubjectExportAction.php
documentation:
  - 'https://eur-lex.europa.eu/eli/reg/2016/679/oj'
modified_files:
  - app/Platform/Actions/Retention/DeleteStoredAudioFilesAction.php
  - app/Platform/Actions/Retention/PurgeExpiredDossierAction.php
  - app/Platform/Actions/Retention/PurgeSubjectActivityLogAction.php
  - tests/Feature/Retention/PurgeLegalRetentionTest.php
  - .ai/rules/retention.md
  - .ai/rules/index.md
parent_task_id: TASK-35
priority: high
type: bug
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Legal retention purge collects uploaded documents and dossier exports, force-deletes the dossier, then deletes those blobs. It never collects audio recordings. audio_recordings.dossier_id cascades on delete, so the rows (and the only disk/path record of the blobs) disappear first. The S3 objects become permanently unreachable. retention:purge-legal reports success while client voice recordings remain in the bucket; files_deleted under-reports.

Audio is already in scope for data-subject export (GenerateDataSubjectExportAction), so it is unambiguously in scope for erasure (AVG art. 17). Mirror the existing stored-document file deletion (delete after commit, same ordering). Do not introduce a shared abstraction between DocumentProcessing and AudioProcessing.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Purging a dossier that has an audio recording removes the recording object from storage
- [x] #2 files_deleted includes the audio objects
- [x] #3 Storage deletion happens after the database transaction commits, matching existing document and export file deletion
- [x] #4 A test fakes the disk, purges a dossier with an audio recording, and asserts the object is gone and counted
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Mirror document/export blob deletion for audio. No shared DocumentProcessing/AudioProcessing abstraction.

1. Add DeleteStoredAudioFilesAction next to DeleteStoredDocumentFilesAction (FilesystemManager, delete after commit, throw on failure). Audio disk/path are required columns, so no null skip.
2. PurgeExpiredDossierAction: collect $dossier->audioRecordings()->get() before the transaction; add count to files_deleted; pass recordings into DeletePipelinesForSubjectsAction with documents/exports; call the audio deleter after commit.
3. PurgeSubjectActivityLogAction: also delete activity for AudioRecording subjects and pipelines morphing to those recordings — otherwise cascadeOnDelete drops the rows and the activity/pipeline leftovers are unreachable. Same “alongside the others” collection, not the P3 whereIn rewrite.
4. Test in PurgeLegalRetentionTest: fake local disk, purge an expired dossier with an audio recording, assert the object is gone and files_deleted includes it. Extend the failing-purge test so an audio blob also survives rollback.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Audio disk/path are required columns, so the deleter has no null skip (unlike exports). Pipelines and activity for audio recordings are included in the same purge path because cascadeOnDelete would otherwise leave morph leftovers.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Legal retention purge now collects audio recordings before force-deleting the dossier, then deletes the blobs after commit. Voice recordings no longer remain in object storage after AVG art. 17 erasure.

## Changes
- DeleteStoredAudioFilesAction mirrors DeleteStoredDocumentFilesAction (no shared DocumentProcessing/AudioProcessing deleter).
- PurgeExpiredDossierAction collects audioRecordings, counts them in files_deleted, passes them to DeletePipelinesForSubjectsAction, and deletes blobs after commit.
- PurgeSubjectActivityLogAction also removes AudioRecording activity and pipelines morphing to those recordings, so cascadeOnDelete cannot leave unreachable leftovers.
- Recorded the cascade-before-blob rule in .ai/rules/retention.md.

## Tests
PurgeLegalRetentionTest: 6 passed (new audio purge + files_deleted count; failing purge leaves the audio blob). PHPStan on the three retention actions: 0 errors.
<!-- SECTION:FINAL_SUMMARY:END -->
