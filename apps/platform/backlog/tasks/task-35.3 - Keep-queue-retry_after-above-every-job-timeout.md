---
id: TASK-35.3
title: Keep queue retry_after above every job timeout
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-13 11:33'
updated_date: '2026-08-13 12:12'
labels:
  - audit
  - gdpr
dependencies: []
references:
  - config/queue.php
  - .env.example
  - app/Platform/Services/Production/ProductionConfigurationValidator.php
  - app/Platform/Jobs/GenerateDataSubjectExportJob.php
modified_files:
  - config/queue.php
  - .env.example
  - phpunit.xml
  - config/transcription.php
  - app/Platform/Services/Production/ProductionConfigurationValidator.php
  - app/AudioProcessing/Jobs/ProcessAudioRecordingJob.php
  - tests/Feature/Platform/ProductionConfigurationValidatorTest.php
  - .ai/rules/config.md
  - .ai/rules/index.md
parent_task_id: TASK-35
priority: high
type: bug
ordinal: 3000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
config/queue.php defaults Redis retry_after to 90 seconds; .env.example sets 180 and documents the invariant. GenerateDataSubjectExportJob times out at 600s; three other jobs at 120s. When retry_after is at or below a job timeout, the worker reservation expires while the job is still running and a second worker can pick it up. ShouldBeUnique does not prevent re-reservation, so a GDPR export can run twice: duplicate S3 artifacts and duplicate audit rows. Environments missing REDIS_QUEUE_RETRY_AFTER inherit 90 and break all four jobs.

Raise the config default above the largest job timeout, reconcile .env.example, and decide whether the DSR export truly needs 600s. ProductionConfigurationValidator already boots from PlatformServiceProvider and already validates queue.default — extend it so unsafe production config fails at boot.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The Redis queue retry_after default in config is strictly greater than the largest job timeout in the app
- [x] #2 The config default and .env.example agree on that invariant
- [x] #3 ProductionConfigurationValidator rejects a configuration where retry_after is less than or equal to any known job timeout
- [x] #4 A unit test covers the validator rejection; do not try to reproduce a double reservation in-process
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Keep GenerateDataSubjectExportJob at 600s (every document and recording for one client from S3). Raise Redis retry_after so it is strictly greater than that timeout.

1. config/queue.php redis retry_after default: 660 (600 + 60s buffer; Laravel requires timeout several seconds below retry_after).
2. .env.example REDIS_QUEUE_RETRY_AFTER=660; comments must name the DSR export timeout, not only transcription.
3. phpunit.xml pin REDIS_QUEUE_RETRY_AFTER=660 so local .env 180 cannot mask the invariant.
4. ProductionConfigurationValidator: fail when queue.connections.redis.retry_after <= max of GenerateDataSubjectExportJob, GenerateDossierExportJob, ProcessUploadedDocumentJob TIMEOUT_SECONDS and transcription.timeouts.job.
5. ProductionConfigurationValidatorTest: set a safe retry_after in beforeEach; dataset-reject 90 and 600; covers() the validator.
6. Record the invariant with record-rule.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Kept GenerateDataSubjectExportJob at 600s; raised retry_after to 660 (timeout + 60s buffer per Laravel docs). Validator reads job TIMEOUT_SECONDS constants plus transcription.timeouts.job so a new longer job fails production boot until retry_after is raised.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Redis retry_after is now 660s — above GenerateDataSubjectExportJob's 600s timeout — so a GDPR export cannot be reserved by a second worker mid-run. Missing REDIS_QUEUE_RETRY_AFTER no longer falls back to 90s.

## Decision
Kept the 600s DSR export timeout. The job comment is right: the archive can contain every document and recording for one client, each read from S3. Lowering it would kill legitimate large exports.

## Changes
- config/queue.php default 660; .env.example 660; phpunit.xml pins 660
- ProductionConfigurationValidator fails boot when redis retry_after ≤ max(DSR 600, dossier export 120, OCR 120, transcription.timeouts.job)
- Comments in transcription config and ProcessAudioRecordingJob point at the DSR timeout, not only 120s

## Tests
ProductionConfigurationValidatorTest: 59 passed (rejects 90, 600, and transcription timeout = retry_after). PHPStan on the validator: 0 errors.

## Follow-up
Update local .env REDIS_QUEUE_RETRY_AFTER from 180 to 660 if it is still set.
<!-- SECTION:FINAL_SUMMARY:END -->
