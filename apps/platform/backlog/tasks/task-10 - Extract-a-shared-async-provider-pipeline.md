---
id: TASK-10
title: Extract a shared async provider pipeline
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-06 09:53'
updated_date: '2026-08-07 08:10'
labels: []
dependencies:
  - TASK-9
documentation:
  - docs/architecture.md
  - docs/backend.md
priority: high
type: enhancement
ordinal: 34000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
AudioProcessing is a line-for-line fork of DocumentProcessing. Seven matched class pairs implement the same async AWS provider pipeline twice, roughly 1100 duplicated lines. Four of the pairs have identical or near-identical line counts - the fail actions at 107 lines each, the mark-failed actions at 74 and 73, the orchestrators at 76 each, the driver factories at 34 and 36. That is copy-paste, not convergent evolution.

The consume commands are the worst pair: after normalising the domain nouns only about 124 of 277 lines differ, including duplicated SQS body and SNS envelope decoding and duplicated critical-failure logging.

The shape is identical in both: claim, start, consume, complete or fail, parameterised by subject model, driver contract and status enum. Extract that once and let OCR and transcription be two configurations of it. Every future provider is otherwise a third copy.

Sequenced smallest pair first, deliberately, so the abstraction is discovered from the duplication rather than designed up front. Depends on the layering work, so the dedup happens against enforced boundaries instead of creating new unconstrained edges.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Sequence 10.1 → 10.4. Shared (L0) owns reusable pipeline mechanisms; DocumentProcessing and AudioProcessing keep domain contracts, enums, and drivers.

10.1 — LazyDriverFactory in Shared: validate key, reject production-forbidden keys before make(), resolve only selected class. Thin domain factories wrap maps. Contracts gain name(). Replace eight config('*.driver') provider writes with driver->name().

10.2 — Shared orchestrator + fail/mark-failed actions parameterized by subject/status (after 10.1).

10.3 — Shared completion + consume/SQS+SNS parsing; fold TASK-15.1 SNS validation into shared parser.

10.4 — Shared processing job shell wrapping the two domain jobs.

Tests after each subtask; Pint on dirty PHP; finalize via AC evidence.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
All subtasks 10.1–10.4 Done. Shared Support now owns the async provider pipeline mechanisms; DocumentProcessing and AudioProcessing keep domain façades.

Post-completion: team shelved extending this approach; redesign tracked separately (readability-first, not more Closure runners).
<!-- SECTION:NOTES:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: nielsvanhoof
created: 2026-08-07 08:10
---
Shelved further investment in the Shared Closure-bag pipeline shape. Follow-up: see the new task for a readability-first redesign (keep useful infra seams; restore linear domain narrative). TASK-18 (Floci) proceeds independently.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Extracted the duplicated OCR/transcription async provider pipeline into Shared (L0) mechanisms with thin domain façades. Sequenced 10.1→10.4 so factories, start/fail, consume/complete, and processing jobs each share one abstraction without violating layering.

## Delivered
- **10.1** LazyDriverFactory + driver `name()`; eight config provider writes removed
- **10.2** Start orchestrator + fail/mark-failed + unpersistable terminal failure helper
- **10.3** SQS consume loop + SNS/EventBridge parser (SNS validation for Textract only) + completion tenant-rescope skeleton
- **10.4** Claim / permanent-failure / Process* job body shared; domain jobs keep queue metadata

## Tests
DocumentProcessing, AudioRecordings, Pipelines retry, Architecture suites green after each subtask.
<!-- SECTION:FINAL_SUMMARY:END -->
