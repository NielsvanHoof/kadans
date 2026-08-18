---
id: TASK-10.1
title: Unify the driver factories and give drivers a name
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-06 09:54'
updated_date: '2026-08-06 22:09'
labels: []
dependencies: []
references:
  - app/DocumentProcessing/Services/Ocr/Drivers/OcrDriverFactory.php
  - app/AudioProcessing/Services/TranscriptionDriverFactory.php
  - app/DocumentProcessing/Contracts/Ocr
  - app/AudioProcessing/Contracts
parent_task_id: TASK-10
priority: high
type: enhancement
ordinal: 35000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The two driver factories are the smallest duplicated pair and the natural starting point.

Both take every concrete driver as a constructor dependency, so resolving either factory eagerly constructs an AWS SDK client even when the configured driver is the mock - in tests, in local development and in CI. Worse, the guard that refuses to use the mock driver in production runs after both drivers have already been constructed.

Separately, driver identity leaks through global config in eight persistence sites that write a provider column by reading the config value directly, despite the whole point of the driver contract. That also blocks testing: you cannot assert which provider a pipeline recorded by swapping the bound driver, you have to mutate config as well.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Resolving a driver factory constructs only the selected driver, not every driver
- [x] #2 The mock-in-production guard runs before any driver is constructed
- [x] #3 The driver contracts expose their own identity and the eight direct config reads are gone
- [x] #4 A test asserts the recorded provider follows the bound driver without touching config
- [x] #5 One factory abstraction serves both OCR and transcription
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add App\Shared\Support\LazyDriverFactory: Application + driver→class-string map + production-forbidden keys + label; validate → production guard → app->make only selected.
2. Rewrite OcrDriverFactory and TranscriptionDriverFactory as thin typed facades over LazyDriverFactory (no concrete driver constructor DI).
3. Add name(): string to StartsDocumentOcr and StartsAudioTranscription; implement on mock + AWS drivers via enum value.
4. Replace eight config provider writes in Process*Job, Complete*, Fail* with injected/bound driver name().
5. Tests: existing factory tests still pass; add AC#1 (mock resolve does not construct AWS driver — spy/binding); AC#2 already covered by production mock test if guard is before make; AC#4 bind fake named driver and assert pipeline.provider without mutating config.
6. Pint + sail artisan test for factory and provider recording tests.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
LazyDriverFactory lives in Shared (L0). Domain factories pass maps; SP bindings still select via config. Eight persistence config reads removed; SP selection reads intentionally remain.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Introduced `App\Shared\Support\LazyDriverFactory` so OCR and transcription factories only construct the selected driver, with the mock-in-production guard running before any `make()`. Driver contracts expose `name()`; the eight pipeline provider writes now use the bound driver identity instead of `config('*.driver')`.

## Changes
- Shared lazy factory + thin `OcrDriverFactory` / `TranscriptionDriverFactory` wrappers
- `name()` on `StartsDocumentOcr` / `StartsAudioTranscription` and all concrete drivers
- Jobs and complete/fail actions inject the start contract for provider persistence

## Tests
`OcrDriverFactoryTest`, `TranscriptionDriverFactoryTest`, `ProcessUploadedDocumentPipelineTest`, job/complete/retry-related suites — 50 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
