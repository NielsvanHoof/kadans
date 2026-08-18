---
id: TASK-36.5
title: 'Make staff audio deletion transactional, audited, and fail-safe'
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:34'
updated_date: '2026-08-14 15:10'
labels:
  - audit
  - gdpr
dependencies: []
references:
  - app/Dossiers/Http/Controllers/AudioRecordingController.php
  - app/Dossiers/Actions/DocumentRequests/DeleteUploadedDocumentAction.php
  - app/Dossiers/Actions/AudioRecordings/UploadAudioRecordingAction.php
  - app/Audit/Enums/AuditEvent.php
modified_files:
  - app/Dossiers/Actions/AudioRecordings/DeleteAudioRecordingAction.php
  - app/Dossiers/Http/Controllers/AudioRecordingController.php
  - app/Audit/Enums/AuditEvent.php
  - lang/en.json
  - lang/nl.json
  - resources/js/types/generated/app.d.ts
  - tests/Feature/AudioRecordings/DeleteAudioRecordingActionTest.php
  - tests/Feature/AudioRecordings/DestroyAudioRecordingTest.php
parent_task_id: TASK-36
priority: high
type: bug
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
AudioRecordingController::destroy deletes the blob before the row, outside a transaction, discards Storage::delete()'s return value, and writes no audit entry (AudioRecording has no LogsActivity and there is no AudioRecordingDeleted case, though Uploaded/Downloaded exist). The sibling DeleteUploadedDocumentAction does all three correctly. store() catches Throwable and reports it, converting ModelNotFoundException into a Sentry bug plus a generic error.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Staff audio deletion does not destroy the blob if the row delete rolls back
- [x] #2 A failed Storage::delete does not leave an unaudited missing object with a live row, or the failure is surfaced
- [x] #3 An AudioRecordingDeleted (or equivalent) audit event is written, matching Uploaded/Downloaded
- [x] #4 store() does not convert ModelNotFoundException into a generic Sentry bug
- [x] #5 Behaviour matches DeleteUploadedDocumentAction on transaction, return-value handling, and audit
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented.

New app/Dossiers/Actions/AudioRecordings/DeleteAudioRecordingAction.php — placed in Dossiers, not AudioProcessing, because AudioRecording is an App\Dossiers\Models model and the upload counterpart (UploadAudioRecordingAction) lives beside it; app/AudioProcessing/Actions/** holds only provider-terminal complete/fail actions. It mirrors DeleteUploadedDocumentAction: snapshot disk/path before the transaction (the model is deleted inside it), then DB::transaction { delete pipelines, write audit, delete row }, then the blob after commit via a private deleteFileWithoutMaskingFailure() using report/report_unless. A fourth copy of that helper is deliberate — duplication over a Shared facade is the standing rule for these domains.

The old order was blob -> pipelines -> row with no transaction, which is the inverse of every other delete path: object storage does not roll back, so unlinking first meant a later failure had already destroyed the file it was meant to protect.

New AuditEvent::AudioRecordingDeleted registered in all nine places: enum case, label() arm, group() arm (reusing the existing 'Audio recordings' group), lang/en.json, lang/nl.json ('Audio-opname verwijderd'), emitted in app/, referenced in tests/, and the generated TypeScript union via npm run types:transform. Confirmed the guards are real: removing the nl.json key fails tests/Unit/LanguageCoverageTest.php with 'Missing keys in nl.json: ["Audio recording deleted"]'.

Controller destroy() is now the three steps .ai/rules/controllers.md requires (authorize, one handle(), return), taking Request to pass the causer. store() catches ModelNotFoundException separately and aborts 404: a dossier deleted between route resolution and the row lock is a gone resource, not a bug, and reporting it filed a Sentry issue for ordinary concurrency behind a 'try again' the user could never satisfy.

Tests — there were NONE for audio deletion before this, which is how an untransactional, unaudited destroy() survived a security audit and the P1/P2 pass:
- tests/Feature/AudioRecordings/DeleteAudioRecordingActionTest.php, covers(DeleteAudioRecordingAction::class): happy path removes row + pipelines + object; the deletion is audited with causer and filename and the frozen label; a rolled-back row delete (AudioRecording::deleting throws) leaves the object intact and writes no audit row; a failed object delete is reported via Exceptions::assertReported while the row stays deleted. The last one binds a Mockery FilesystemManager into the container rather than faking the Storage facade, because the action takes an injected manager — and because Flysystem treats deleting a missing object as success, so a bad path cannot produce the failure.
- tests/Feature/AudioRecordings/DestroyAudioRecordingTest.php: owner deletes and it is audited; read-only member gets 403 and nothing moves (row, bytes, trail); a completed (locked) dossier is 403 even for an owner.

Observation, not acted on: DeleteUploadedDocumentAction still writes no audit entry, and AuditEvent has no DocumentDeleted case. So staff document deletion remains unaudited while staff audio deletion no longer is. That is outside this subtask's ACs (which are about audio) and is worth raising with the user as follow-up rather than folding in silently. It also makes AC#5's 'matches DeleteUploadedDocumentAction on ... audit' one-sided: this action matches the sibling on transaction and return-value handling and exceeds it on audit, which is what AC#3 requires.

Verified: full suite 1192 tests / 6792 assertions pass; coverage 87.5% against the 87% floor; PHPStan level 8, Rector, Pint clean; npm run types:check regenerates cleanly with the new union member.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Staff audio deletion is now transactional, audited and fail-safe.

New DeleteAudioRecordingAction (in Dossiers, beside its upload counterpart) replaces the controller's inline orchestration: pipelines, audit entry and row delete inside one transaction, blob delete after commit with report_unless on the discarded return value. The old order was blob first with no transaction — the inverse of every other delete path here, so a later failure had already destroyed the file it was meant to protect.

AuditEvent::AudioRecordingDeleted fills the gap where uploads and downloads were both recorded but deletion left no trace at all; registered across all nine required places including the generated TypeScript union, and the language guard was confirmed to catch a missing locale key. destroy() is down to the three steps controllers.md allows, and store() now 404s a concurrently deleted dossier instead of filing a Sentry issue behind an unsatisfiable 'try again'.

Verified: audio deletion had NO test before this. Two new files cover the action (happy path, audit contents, rolled-back row delete leaving the object intact, reported object-delete failure) and the route (owner allowed, read-only 403 with nothing moved, locked dossier 403). Full suite 1192 tests / 6792 assertions pass, coverage 87.5% against the 87% floor, PHPStan level 8 / Rector / Pint clean.

One asymmetry deliberately left alone and raised instead of folded in: DeleteUploadedDocumentAction still writes no audit entry and AuditEvent has no DocumentDeleted case, so staff document deletion remains unaudited. Outside this subtask's acceptance criteria.
<!-- SECTION:FINAL_SUMMARY:END -->
