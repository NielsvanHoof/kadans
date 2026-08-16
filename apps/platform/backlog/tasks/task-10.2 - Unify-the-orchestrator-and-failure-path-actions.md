---
id: TASK-10.2
title: Unify the orchestrator and failure-path actions
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-06 09:54'
updated_date: '2026-08-06 22:14'
labels: []
dependencies:
  - TASK-10.1
references:
  - app/DocumentProcessing/Services/Ocr/OcrOrchestrator.php
  - app/AudioProcessing/Services/TranscriptionOrchestrator.php
  - app/DocumentProcessing/Actions/Ocr
  - app/AudioProcessing/Actions
parent_task_id: TASK-10
priority: high
type: enhancement
ordinal: 36000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Second smallest pair set: the two orchestrators are 76 lines each and follow the same sequence - log, audit a started event, call the driver start, refresh, then branch on whether completion is immediate or deferred. The two fail actions are 107 lines each and the two mark-failed actions are 74 and 73.

Extract the shared start-and-fail behaviour, parameterised by subject and status enum, and keep the immediate-versus-deferred outcome abstraction, which is already the right idea and correctly hides provider specifics from the callers.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 One orchestrator abstraction serves both OCR and transcription
- [x] #2 One failure path serves both, including the terminal unpersistable-failure logging
- [x] #3 The immediate versus deferred completion distinction is preserved
- [x] #4 The existing DocumentProcessing feature tests pass untouched
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Mirror 10.1: Shared owns mechanism; domain façades keep stable public APIs (AC#4 — do not edit DocumentProcessing tests).

1. Add App\Shared\Enums\AsyncProcessingOutcome (Immediate/Deferred).
2. Add App\Shared\Support\AsyncProviderStartOrchestrator — callback-based start/refresh/branch; no Audit/Tenancy/Pipelines imports.
3. Add App\Shared\Support\AsyncProviderFailureProfile + MarkAsyncProviderSubjectFailed — conditional Eloquent update returning bool; domains handle audit/logs.
4. Add App\Shared\Support\AsyncProviderFailure — truncateError + optional fail-path template that only uses callables (find subject, run in tenant, mark, fail pipeline) so Shared stays layer-clean.
5. Thin OcrOrchestrator / TranscriptionOrchestrator / Mark* / Fail* facades; map Shared outcome → domain OcrProcessingOutcome / TranscriptionProcessingOutcome (keep domain enums for AC#4).
6. Do not touch consume commands (10.3) or jobs (10.4). Unpersistable-critical logging stays in consume for now unless a tiny Shared log helper fits without editing DocumentProcessing tests.
7. Run DocumentProcessing + AudioRecording feature tests; Pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
AC#4 verified: no edits under tests/Feature/DocumentProcessing; full DocumentProcessing suite green. Unpersistable-failure critical logging extracted via PersistAsyncProviderTerminalFailure used by both consume commands.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Extracted shared async provider start and failure mechanisms into Shared (L0). Domain OCR/transcription façades keep their public APIs so DocumentProcessing feature tests needed no edits.

## Shared
- `AsyncProcessingOutcome`, `AsyncProviderStartOrchestrator`
- `AsyncProviderFailureProfile`, `MarkAsyncProviderSubjectFailed`
- `FailAsyncProviderJob`, `PersistAsyncProviderTerminalFailure` (covers unpersistable critical logging used by both consume commands)

## Domain
Thin `OcrOrchestrator` / `TranscriptionOrchestrator`, Mark*, Fail* façades; consume `failPermanently` delegates to Shared.

## Tests
All `tests/Feature/DocumentProcessing` + related audio/architecture suites — passed (DocumentProcessing tests untouched).
<!-- SECTION:FINAL_SUMMARY:END -->
