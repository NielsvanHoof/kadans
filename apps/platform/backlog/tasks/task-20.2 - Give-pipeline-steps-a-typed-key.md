---
id: TASK-20.2
title: Give pipeline steps a typed key
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 10:59'
updated_date: '2026-08-08 11:13'
labels: []
dependencies: []
parent_task_id: TASK-20
priority: high
type: enhancement
ordinal: 66000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
PipelineType::stepKeys() returns raw strings ('submit', 'provider_processing', 'process_results', 'transcribe', 'build_archive', 'store_archive') and roughly thirty call sites across DocumentProcessing, AudioProcessing and Dossiers pass those as string literals. PrepareProviderPipelineForProviderResultAction hardcodes 'submit'.

A typo does not fail at boot or in a static check — it surfaces as a ModelNotFoundException from PipelineStateAction::lockStep()'s firstOrFail(), inside a queue worker, per tenant, only on the code path that uses the bad key. A backed enum removes the whole failure class and makes the per-type step vocabulary discoverable.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A PipelineStepKey backed enum holds every step key used by PipelineType::stepKeys()
- [x] #2 PipelineType::stepKeys() returns a list of PipelineStepKey
- [x] #3 PipelineStep::$key casts to PipelineStepKey and PipelineStateAction accepts it
- [x] #4 No string literal step keys remain in app/
- [x] #5 Existing OCR, transcription and export feature tests still pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add App\Pipelines\Enums\PipelineStepKey with the six keys used across the three pipeline types (Submit, ProviderProcessing, ProcessResults, Transcribe, BuildArchive, StoreArchive).
2. Change PipelineType::stepKeys() to return list<PipelineStepKey>; update CreatePipelineAction to write ->value.
3. Cast PipelineStep::$key to PipelineStepKey; check the Data object and any frontend consumers for the string shape.
4. Change PipelineStateAction to accept ?PipelineStepKey and update lockStep to compare on ->value.
5. Update every call site in DocumentProcessing, AudioProcessing, Dossiers, Pipelines and the tests.
6. Handle MonitorPipelinesCommand, which passes $pipeline->current_stage (a nullable string column) as the step key — resolve it through PipelineStepKey::tryFrom.
7. Run the Pipelines, DocumentProcessing, AudioRecordings and Dossiers suites plus pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Added App\Pipelines\Enums\PipelineStepKey with the six keys the three pipeline types declare. PipelineType::stepKeys() now returns list<PipelineStepKey>.

Cast both ends of the lookup, not just the step: PipelineStep::$key and Pipeline::$current_stage are both PipelineStepKey now. That was necessary rather than cosmetic — PipelineStepTransitions writes 'current_stage' => $step->key, so leaving current_stage a plain string would have pushed an enum instance into an uncast attribute. It also means MonitorPipelinesCommand can keep passing $pipeline->current_stage straight through to PipelineStateAction with no tryFrom step, which the plan had expected to need.

PipelineStateAction::handle() and lockStep() take ?PipelineStepKey and compare on ->value. PrepareProviderPipelineForProviderResultAction's $providerStepKey is typed too, so AudioProcessing passes PipelineStepKey::Transcribe and DocumentProcessing passes PipelineStepKey::ProviderProcessing.

Data objects follow: PipelineShowData, PipelineIndexRowData and PipelineData expose ?PipelineStepKey $currentStage, PipelineStepData exposes PipelineStepKey $key. The wire format is unchanged (Spatie Data serialises backed enums to their value), so the Inertia prop contract fixtures did not move. The generated TypeScript gained App.Pipelines.Enums.PipelineStepKey as a union and the three current_stage fields plus PipelineStepData.key narrowed from string to it. resources/js/features/pipelines/status/pipeline-status.ts still takes a string, which the union is assignable to — narrowing that signature would be a genuine follow-up but sits outside this task's criteria.

PipelineTransitionsTest used synthetic keys ('process', 'first', 'second', 'later', 'sooner', 'next', 'skipped') that the enum rightly rejected; they were only ever placeholders for ordering, so they map onto real keys. PipelineStepFactory's default key moved from 'process' to PipelineStepKey::Submit.

Validation: full suite via vendor/bin/sail artisan test --compact -> 844/849 passing. The 4 failures and 1 error (LanguageCoverageTest x2, InertiaPropContractTest dossiers/index, WorkspaceMemberManagementTest, UniqueJobLockExpiryTest ProcessUploadedDocumentJob::$timeout) were each confirmed to fail with these changes stashed, so all are pre-existing on develop. Targeted runs: tests/Feature/Pipelines -> 84 passed; tests/Feature/{DocumentProcessing,AudioRecordings,Dossiers} -> 230 passed. vendor/bin/sail npm run types:check -> only the pre-existing aria-current error in index-pagination.test.tsx. Pint passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Replaced the stringly-typed pipeline step keys with a PipelineStepKey backed enum. PipelineType::stepKeys() returns it, PipelineStep::$key and Pipeline::$current_stage cast to it, and PipelineStateAction plus PrepareProviderPipelineForProviderResultAction accept it, so a mistyped key is now a type error instead of a ModelNotFoundException surfacing inside a queue worker. All ~30 call sites across DocumentProcessing, AudioProcessing, Dossiers, the tests and the factories were converted; no step-key string literals remain outside the enum. The JSON wire format is unchanged, and the generated TypeScript narrowed from string to the new union. Verified with the full backend suite (844/849, the 5 remaining confirmed pre-existing on develop) and types:check.
<!-- SECTION:FINAL_SUMMARY:END -->
