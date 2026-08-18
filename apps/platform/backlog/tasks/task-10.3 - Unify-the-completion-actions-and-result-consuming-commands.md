---
id: TASK-10.3
title: Unify the completion actions and result-consuming commands
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-06 09:54'
updated_date: '2026-08-06 22:18'
labels: []
dependencies:
  - TASK-10.2
references:
  - >-
    app/DocumentProcessing/Console/Commands/Ocr/ConsumeTextractResultsCommand.php
  - app/AudioProcessing/Console/Commands/ConsumeTranscribeResultsCommand.php
  - app/DocumentProcessing/Actions/Ocr/CompleteTextractExtractionAction.php
  - app/AudioProcessing/Actions/CompleteTranscribeJobAction.php
parent_task_id: TASK-10
priority: high
type: enhancement
ordinal: 37000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The two completion actions are 246 and 275 lines. The two consume commands are 272 and 282 lines and differ in only about 124 lines once domain nouns are normalised, including duplicated SQS body and SNS envelope JSON decoding and duplicated critical-failure logging.

The queue-draining, envelope-parsing and dispatch loop is provider-agnostic; only the payload shape and the completion action differ. Extract the loop once.

Note the envelope parsing is also unverified - see the SNS signature validation task - so if both land, coordinate so the shared parser is the place the signature check goes.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 One command abstraction drains both result queues
- [x] #2 SQS body and SNS envelope decoding exists in one place
- [x] #3 One completion abstraction serves both providers, preserving the per-subject tenant re-scoping
- [x] #4 The existing DocumentProcessing and AudioProcessing feature tests pass untouched
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Mirror 10.1/10.2: Shared mechanism + domain façades. Do not edit DocumentProcessing or AudioRecordings feature tests.

1. AsyncProviderEnvelopeMode (SnsNotification | EventBridgeDetail) + AsyncProviderQueuePayloadParser (optional MessageValidator for SNS only).
2. ConsumeAsyncProviderResults — shared SQS long-poll/delete/exhaust-fail loop; reuse PersistAsyncProviderTerminalFailure.
3. CompleteAsyncProviderJob — shared find/tenant-scope/terminal/success/fail/defer skeleton via closures.
4. Thin Consume* commands and Complete* actions; keep Artisan signatures and handle(array): bool.
5. Run DocumentProcessing + AudioRecordings suites untouched; Pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
SNS signature validation lives in AsyncProviderQueuePayloadParser for SnsNotification mode only; EventBridgeDetail never validates SNS. AC#4: DocumentProcessing + AudioRecordings feature tests untouched and green.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Extracted shared SQS drain, envelope parsing (SNS with optional signature validation / EventBridge detail), and completion tenant-rescope skeleton into Shared. Domain consume commands and Complete* actions remain thin façades with unchanged public APIs.

## Shared
- `AsyncProviderEnvelopeMode`, `AsyncProviderQueuePayloadParser`
- `ConsumeAsyncProviderResults` (reuses `PersistAsyncProviderTerminalFailure`)
- `CompleteAsyncProviderJob`

## Tests
`tests/Feature/DocumentProcessing`, `tests/Feature/AudioRecordings`, `tests/Architecture` — 118 passed; those feature test files were not edited.
<!-- SECTION:FINAL_SUMMARY:END -->
