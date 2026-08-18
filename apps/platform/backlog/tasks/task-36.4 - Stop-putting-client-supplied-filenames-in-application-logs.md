---
id: TASK-36.4
title: Stop putting client-supplied filenames in application logs
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:34'
updated_date: '2026-08-14 15:01'
labels:
  - audit
  - gdpr
dependencies: []
references:
  - app/DocumentProcessing/Services/Ocr/OcrOrchestrator.php
  - app/DocumentProcessing/Jobs/ProcessUploadedDocumentJob.php
  - app/AudioProcessing/Services/TranscriptionOrchestrator.php
  - app/AudioProcessing/Jobs/ProcessAudioRecordingJob.php
  - tests/Unit/DossierLockOrderTest.php
modified_files:
  - app/DocumentProcessing/Services/Ocr/OcrOrchestrator.php
  - app/DocumentProcessing/Jobs/ProcessUploadedDocumentJob.php
  - app/DocumentProcessing/Actions/Ocr/CompleteTextractExtractionAction.php
  - app/DocumentProcessing/Services/Ocr/Drivers/MockOcrExtractor.php
  - app/AudioProcessing/Services/TranscriptionOrchestrator.php
  - app/AudioProcessing/Jobs/ProcessAudioRecordingJob.php
  - app/AudioProcessing/Services/Drivers/MockAudioTranscriber.php
  - tests/Unit/DocumentFilenameLoggingTest.php
  - tests/Feature/DocumentProcessing/ProcessUploadedDocumentJobTest.php
  - docs/security.md
parent_task_id: TASK-36
priority: high
type: bug
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
OcrOrchestrator, ProcessUploadedDocumentJob, TranscriptionOrchestrator, and ProcessAudioRecordingJob put client-supplied original_filename into Log:: context, beside correct logAuditActivity calls. Dutch filenames look like Loonstrook_J_de_Vries_maart.pdf. The log stack has different retention and access than the audit trail and is untouched by retention:purge-legal and AnonymizeDataSubjectAction, so an erasure request cannot be fully honoured.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Application Log:: context for OCR, upload processing, transcription, and audio processing no longer includes original_filename
- [x] #2 Row identity in those logs still uses id and tenant_id
- [x] #3 An erasure or legal purge does not leave client-supplied filenames in the general log from these paths
- [x] #4 A test or grep-backed assertion covers the former call sites
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented.

Seven Log:: context sites removed, not the four the task listed. The extra three:
- app/DocumentProcessing/Actions/Ocr/CompleteTextractExtractionAction.php:148 — the production Textract completion path. The manual review had classified this line as an audit call; the source scanner caught it. This was the most important one of the set.
- app/DocumentProcessing/Services/Ocr/Drivers/MockOcrExtractor.php and app/AudioProcessing/Services/Drivers/MockAudioTranscriber.php — local-only drivers, but a local log is still a log nobody purges, and exempting them would have weakened the guard test. Their use of the filename inside generated extraction/transcript CONTENT is left alone; tests assert on it.

All seven already carried id + tenant_id, so AC#2 needed no additions. The ~15 audit/activitylog sites, DB writes, pipeline metadata, Content-Disposition headers, DTOs and notification payloads are untouched.

Enforced two ways, deliberately:
1. tests/Unit/DocumentFilenameLoggingTest.php — source scanner over app/, modelled on tests/Unit/DossierLockOrderTest.php. It walks brackets rather than matching one line, so a key anywhere in a multi-line context array is seen, and it matches only Log:: (audit calls are allowed to name the file). Includes the DossierLockOrderTest-style self-test: it proves the scanner reads real context keys, reads past the first line, and would actually flag a forbidden key in a fixture — a regex that silently matched nothing would make every assertion pass. A third dataset test asserts the four call sites still identify their row (id + tenant_id), so the fix cannot leave an uncorrelatable log line.
2. tests/Feature/DocumentProcessing/ProcessUploadedDocumentJobTest.php — a full OCR run with Log::listen, asserting no record contains 'Loonstrook_J_de_Vries_maart.pdf', plus that the audit trail still does. This catches what a source scanner cannot: a filename arriving via an interpolated message or a collaborator.

Both layers were confirmed non-vacuous by re-adding original_filename to OcrOrchestrator: the scanner fails with the violation list and the behavioural test fails with 'Expecting ... not to contain Loonstrook_J_de_Vries_maart.pdf'.

docs/security.md gained a paragraph under Audit and retention stating why the audit trail may carry filenames and the application log may not, naming both enforcing tests. That section previously promised the client's trail is removed on erasure, which was not true while filenames sat in the log stack.

Note for future edits in these files: project tooling strips inline // comments from app/, so the rationale lives in the guard test's header docblock rather than at each call site. This also matches the repo's own PHP rule preferring PHPDoc over inline comments.

Verified: 222 tests pass across tests/Unit, tests/Architecture, tests/Feature/DocumentProcessing and tests/Feature/AudioRecordings; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Client-supplied filenames no longer reach the application log from the OCR or transcription paths.

Seven Log:: context sites lost their original_filename key — three more than the task listed. The extra one that matters is CompleteTextractExtractionAction, the production Textract completion path, which the manual review had misclassified as an audit call and the source scanner caught; the other two are the local mock drivers, cleaned so the guard test needs no exemption. All seven already logged id + tenant_id, so row identity is unchanged, and the ~15 audit/activitylog sites still record the filename because that trail is purged by retention and erasure.

Two enforcing layers, both confirmed to fail when a leak is reintroduced: tests/Unit/DocumentFilenameLoggingTest.php scans every Log:: context array in app/ (bracket-walking, so multi-line arrays are covered, with a self-test proving the scanner is not silently matching nothing), and a full OCR run in ProcessUploadedDocumentJobTest asserts no log record contains the filename while the audit trail still does.

docs/security.md now states the rule and names both tests; that section had promised the client's trail is removed on erasure, which was untrue while filenames sat in an unpurged log stack.

Verified: 222 tests pass across Unit, Architecture, DocumentProcessing and AudioRecordings; PHPStan level 8, Rector and Pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
