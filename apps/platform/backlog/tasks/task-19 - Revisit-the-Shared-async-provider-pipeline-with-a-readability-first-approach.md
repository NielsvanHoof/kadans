---
id: TASK-19
title: Revisit the Shared async provider pipeline with a readability-first approach
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-07 08:10'
updated_date: '2026-08-07 11:05'
labels: []
dependencies:
  - TASK-10
references:
  - app/Shared/Support/AsyncProviderStartOrchestrator.php
  - app/DocumentProcessing/Services/Ocr/OcrOrchestrator.php
  - app/Shared/Support/LazyDriverFactory.php
  - docs/architecture.md
documentation:
  - docs/architecture.md
  - docs/backend.md
priority: low
type: chore
ordinal: 63000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
TASK-10 extracted OCR/transcription duplication into Shared via Closure-heavy facades (`AsyncProviderStartOrchestrator`, `FailAsyncProviderJob`, `ProcessAsyncProviderSubject`, etc.). That preserved L0 layering but made domain call sites (especially `OcrOrchestrator` / `TranscriptionOrchestrator` and Fail*) harder to read than the original linear code. The team is shelving further investment in that shape.

Keep what clearly earned its keep until redesign (candidate keepers: `LazyDriverFactory` + driver `name()`, queue payload parser / consume loop, claim + path-scoped permanent failure). Do not extend the Closure-bag pattern to new providers.

Later work should redesign around readability: Shared owns infrastructure seams only; domain owns narrative flow (linear orchestrators/fail actions). Prefer typed hook objects or deliberate duplication of tiny sequences over multi-callback Shared runners. Optionally roll back the worst readability regressions when this task is picked up — research the codebase then; do not prescribe a plan at creation time.

Related: TASK-18 (Floci) is independent and should proceed without deepening the Shared pipeline abstraction.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A written approach replaces Closure-bag Shared runners for narrative OCR/transcription flow (document the chosen pattern before large edits)
- [x] #2 Domain orchestrators and fail paths are readable linear code again, or an equally scannable typed collaborator — not five+ inline Closures
- [x] #3 Infrastructure keepers that still pay rent (e.g. lazy drivers, envelope parse/consume, claim/fail writes) remain Shared or are explicitly re-homed with rationale
- [x] #4 No new provider is onboarded by copying the shelved Closure-bag facade style
- [x] #5 DocumentProcessing and AudioRecordings feature suites stay green; architecture tests still forbid Shared importing higher domains
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Add domain Transitions for processing status (mirror DossierExportTransitions).

1. UploadedDocumentProcessingTransitions: claim, failWhileProcessing (job id), failForPath (job exhaustion).
2. AudioTranscriptionTransitions: claim, failWhileProcessing, failForPath, resetForRetry.
3. Wire Process*Job + Mark*FailedAction; Complete* success status writes where they currently inline-update.
4. Feature tests for transitions; run DocumentProcessing + AudioRecordings suites.
5. Pint. Partial delivery toward TASK-19 readability ACs — do not close TASK-19 until remaining ACs are met.

Remaining (Closure-bag Shared code already absent; orchestrators already linear):
6. Document readability-first approach in docs/architecture.md + docs/backend.md (AC#1/#4): domain owns narrative (linear orchestrators, Fail*, Transitions); Shared stays infra-only; no multi-callback Shared runners for new providers.
7. Explicit keeper disposition (AC#3): claim/fail → domain Transitions; driver factories and SQS consume stay domain (deliberate duplication — SNS vs EventBridge parsers differ; two-driver factories do not need LazyDriverFactory).
8. Optionally add driver name() so pipeline provider fields do not re-read config — only if cheap.
9. Run DocumentProcessing + AudioRecordings + architecture Pest; finalize TASK-19.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Partial: added UploadedDocumentProcessingTransitions + AudioTranscriptionTransitions; wired Process*Job, Mark*Failed, Complete* success writes; feature tests for transitions.

Research: AsyncProvider* / LazyDriverFactory are gone from the tree; OcrOrchestrator and TranscriptionOrchestrator are already linear; Fail* are linear. Remaining ACs are documentation + keeper disposition + suite verification.

Validation: DocumentProcessing + AudioRecordings feature suites green; tests/Architecture (incl. Shared layering) green. Approach documented in docs/architecture.md (Async provider processing) and docs/backend.md; standing rule in .ai/rules/document-processing-audio-processing-shared.md. Keeper disposition: claim/fail → domain Transitions; driver factories + SQS consume remain domain (deliberate duplication). Closure-bag Shared runners already absent from tree; orchestrators/Fail* already linear.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Finished the readability-first revisit of the shelved Shared async-provider pipeline.

- Domain Transitions for OCR/transcription status (claim/complete/fail/retry) wired into Process*/Mark*/Complete*.
- Documented approach: domain owns linear narrative; Shared stays infra-only; no multi-callback Shared runners for new providers (docs/architecture.md, docs/backend.md, .ai/rules).
- Explicit keeper disposition: Transitions re-home claim/fail; driver factories and SQS consume stay domain-local.
- Verified: DocumentProcessing + AudioRecordings suites pass; Architecture tests (Shared must not import higher domains) pass.
<!-- SECTION:FINAL_SUMMARY:END -->
