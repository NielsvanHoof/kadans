---
id: TASK-23
title: Validate the transcription driver in production like the OCR driver
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-08 13:34'
updated_date: '2026-08-11 08:18'
labels: []
milestone: m-0
dependencies: []
references:
  - app/Platform/Services/Production/ProductionConfigurationValidator.php
  - config/transcription.php
documentation:
  - docs/security.md
priority: medium
type: bug
ordinal: 76000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`ProductionConfigurationValidator` fails boot in production on 24 misconfigurations, including `ocr.driver` not being `textract`. It never checks `transcription.driver`.

The asymmetry means production can boot with `TRANSCRIPTION_DRIVER=mock` and silently serve fabricated transcripts as if they came from Amazon Transcribe. Staff would read invented text attributed to a client audio recording, with nothing in the UI or logs marking it as fake. The two async provider paths are otherwise built as mirrors of each other (`OcrOrchestrator`/`TranscriptionOrchestrator`, matching driver factories, matching SQS consume commands), so the missing guard is an oversight rather than a decision.

The existing `matches()` helper already does exactly what is needed; this is one entry in the array plus a test alongside the existing production-validator coverage.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Booting in production with a non-Transcribe transcription driver throws, naming `transcription.driver` among the invalid keys
- [x] #2 Booting in production with the Transcribe driver still passes validation
- [x] #3 The production configuration validator test covers the new key the same way it covers `ocr.driver`
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Findings
The acceptance criteria are already satisfied in tree (landed in 5540f7b on 2026-08-10 as part of a broader production-validator hardening pass). Task metadata was never closed.

## Already present
1. `ProductionConfigurationValidator` calls `$this->matches('transcription.driver', 'transcribe')` beside the OCR check, with a comment explaining why.
2. `ProductionConfigurationValidatorTest` baseline sets `transcription.driver` => `transcribe`; the unsafe-config dataset includes `'mock transcription' => ['transcription.driver', 'mock']`; non-production fixture also sets mock transcription.
3. `.ai/rules/config.md` and `docs/security.md` already document the Transcribe requirement.

## Verification steps
1. Re-read validator + test against AC #1–#3.
2. Run `vendor/bin/sail artisan test --compact tests/Feature/Platform/ProductionConfigurationValidatorTest.php`.
3. If green, finalize task with evidence; no further code changes expected unless tests fail.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verified existing implementation already meets AC #1–#3 (validator match + test dataset). Ran ProductionConfigurationValidatorTest (56 passed) plus filtered mock-transcription and safe-config cases. No code changes required.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Closed without new code: the missing production guard for `transcription.driver` was already shipped in `5540f7b` (2026-08-10) alongside other production-validator hardening, but TASK-23 stayed open.

`ProductionConfigurationValidator` already requires `transcription.driver === 'transcribe'` via `matches()`, mirroring `ocr.driver` / Textract. Baseline and dataset coverage in `ProductionConfigurationValidatorTest` already exercise the happy path and `'mock transcription'` rejection. Shared rule `.ai/rules/config.md` already documents the Transcribe requirement.

## Tests
- `vendor/bin/sail artisan test --compact tests/Feature/Platform/ProductionConfigurationValidatorTest.php` — 56 passed
- Filtered cases: `mock transcription` and `safe production configuration passes validation` — passed

## Notes
`docs/security.md` still lists OCR/Textract in the production-guard paragraph but not Transcribe; optional doc polish only — not required by acceptance criteria.
<!-- SECTION:FINAL_SUMMARY:END -->
