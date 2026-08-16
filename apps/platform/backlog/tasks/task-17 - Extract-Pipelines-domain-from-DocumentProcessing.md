---
id: TASK-17
title: Extract Pipelines domain from DocumentProcessing
status: Done
assignee:
  - '@niels'
created_date: '2026-08-06 16:23'
updated_date: '2026-08-06 20:59'
labels:
  - architecture
  - refactor
dependencies: []
references:
  - app/DocumentProcessing/Models/Pipeline.php
  - app/DocumentProcessing/Support/PipelineRetryRunnerRegistry.php
  - app/DocumentProcessing/Contracts/PipelineRetryRunner.php
  - app/DocumentProcessing/Policies/PipelinePolicy.php
  - tests/Pest.php
  - bootstrap/providers.php
documentation:
  - docs/architecture.md
  - docs/adding-a-domain.md
  - docs/testing.md
priority: medium
type: enhancement
ordinal: 61000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Pipeline run tracking (Pipeline/PipelineStep models, create/retry/state actions, retry runner contract + registry, policy, enums, monitor command, DTOs/events) currently lives inside DocumentProcessing even though audio transcription and dossier exports also create and retry pipelines. That makes DocumentProcessing look like a generic host for every long-running process, and leaves the retry registry feeling misplaced.

Extract a first-class Pipelines domain so run tracking has a clear owner and DocumentProcessing can shrink to OCR/extraction.

Locked decisions for the future worker:
- Namespace: App\Pipelines (plural).
- Layer: L4 sibling of Clients, Dossiers, DocumentProcessing, AudioProcessing — not Shared and not L3. PipelinePolicy::download already depends on DossierExport/DossierPermission; L4 avoids inventing a download-auth inversion in this extraction. Document Pipelines as a deliberate sibling of those processing domains.
- Reporting stays thin: PipelineController and Fetch* queries stay in Reporting; only imports change.
- Frontend features/pipelines stays; no rename.
- Morph aliases pipeline / pipeline_step stay stable; update FQCNs only. No DB migrations.
- OCR-specific runners and TextractExtractionPipeline stay with DocumentProcessing; only import paths change.

Follow docs/adding-a-domain.md for provider, permissions, morph map, and command path wiring.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Pipeline run-tracking code lives under App\Pipelines; DocumentProcessing retains only OCR/extraction concerns
- [x] #2 Morph map aliases pipeline and pipeline_step remain unchanged; only class FQCNs update
- [x] #3 Architecture docs and Pest layer rules list Pipelines at L4 as a sibling of DocumentProcessing, AudioProcessing, and Dossiers
- [x] #4 Retry runners still register from owning domains into a Pipelines-owned registry; retry behaviour for OCR, audio, and export is unchanged
- [x] #5 Reporting pipeline UI continues to work via updated imports; features/pipelines frontend path is unchanged
- [x] #6 Architecture tests and focused pipeline/OCR/audio/export tests pass; docs reflect the new domain split
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Move pipeline run-tracking code into app/Pipelines (git mv), dropping the redundant Actions/Console `Pipelines` subfolder since the domain itself is now Pipelines:
   - Models/Pipeline.php, Models/PipelineStep.php
   - Enums/{PipelinePermission,PipelineStatus,PipelineStepOperation,PipelineStepStatus,PipelineType}.php
   - Actions/{BroadcastPipelineUpdatedAction,CreatePipelineAction,DeletePipelineAction,DeletePipelinesForSubjectsAction,PipelineStateAction,PrepareProviderPipelineForProviderResultAction,ResolveProviderPipelineForSubjectAction,RetryPipelineAction}.php
   - Console/Commands/MonitorPipelinesCommand.php
   - Data/{PipelineData,PipelineIndexRowData,PipelineShowData,PipelineStepData,PipelineSubjectData,PipelineUpdatedPayloadData}.php
   - Events/PipelineUpdated.php, Policies/PipelinePolicy.php, Transitions/PipelineTransitions.php
   Update namespace declarations in each moved file.

2. Bulk-update every cross-reference (app/, database/factories, tests/, routes/, config/, bootstrap/) from `App\DocumentProcessing\{Models,Actions\Pipelines,Console\Commands\Pipelines,Data,Events,Policies,Transitions}\...` and the 5 moved Enum FQCNs to their new `App\Pipelines\...` paths. DocumentProcessing keeps OCR-only Enums (DocumentProcessingStatus, OcrDriver, OcrProcessingOutcome, TranscriptionStatus, ExtractionFieldSensitivity), Contracts/Ocr, Services/Ocr, Jobs/ProcessUploadedDocumentJob, Ai/DocumentOverviewAgent.

3. Design the retry-runner inversion (the actual architectural piece — RetryPipelineAction currently hardcodes AudioProcessing/Dossiers job classes and per-type reset fields, which is a lower domain reaching into siblings):
   - New `App\Pipelines\Contracts\PipelineRetryRunner` (type(), subjectClass(), resetSubject(Model), dispatch(Pipeline, Model))
   - New `App\Pipelines\Support\PipelineRetryRunnerRegistry` (register/for), bound as a singleton in a new `App\Pipelines\PipelinesServiceProvider` (also takes over the `Gate::policy(Pipeline::class, PipelinePolicy::class)` call, removed from DocumentProcessingServiceProvider)
   - Three runner implementations registered from their owning domain's ServiceProvider::boot(): `App\DocumentProcessing\Retry\OcrPipelineRetryRunner`, `App\AudioProcessing\Retry\AudioTranscriptionPipelineRetryRunner`, `App\Dossiers\Retry\DossierExportPipelineRetryRunner` (the three currently-empty Retry/ dirs)
   - Rewrite RetryPipelineAction to resolve a runner from the registry instead of `match()`-ing on PipelineType directly

4. Wire the new domain: bootstrap/providers.php (add PipelinesServiceProvider), bootstrap/app.php (add app/Pipelines/Console/Commands to withCommands), AppServiceProvider morph map (FQCNs only, aliases `pipeline`/`pipeline_step` unchanged).

5. Formalize AudioProcessing and Pipelines as L4 layer siblings (locked decision) in tests/Pest.php: add both to every existing not->toUse ban list that currently mentions DocumentProcessing (Shared, Accounts, User, Tenancy, Audit, Questionnaires, ClientPortal), add new expect() rules for App\Pipelines and App\AudioProcessing mirroring DocumentProcessing's shape, update the L4 comment list. Add both to tests/Architecture/AppArchitectureTest.php's `arch()->preset()->laravel()->ignoring([...])` list (AudioProcessing already there; add Pipelines).

6. Move the Pipelines-only tests (git mv, no namespace lines to fix in Pest test files): PipelineTest, PipelinePolicyTest, PipelineTransitionsTest, PipelineUpdatedBroadcastTest, RetryPipelineActionTest from tests/Feature/DocumentProcessing/ to tests/Feature/Pipelines/. Leave OCR-named tests and PipelineControllerTest (Reporting's controller) in place, imports only.

7. Update docs/architecture.md: domain table + L4 layering list + provider list to include Pipelines (and the pre-existing gap where AudioProcessing was missing from both).

8. Frontend: resources/js/features/pipelines/types.ts aliases from `App.DocumentProcessing.{Data,Enums}.*` to `App.Pipelines.{Data,Enums}.*`; regenerate resources/js/types/generated/app.d.ts via artisan typescript:transform. No page/feature path changes.

9. Run `vendor/bin/sail bin pint --dirty --format agent`, then targeted tests: tests/Architecture, tests/Feature/Pipelines, tests/Feature/DocumentProcessing, tests/Feature/AudioRecordings, tests/Feature/Dossiers/DossierExportTest, tests/Feature/Platform/{DomainServiceProvidersTest,MorphMapTest}, tests/Feature/Tenancy/PermissionRegistryTest, tests/Unit/QueueableTagsTest.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implementation: moved Pipeline/PipelineStep models, PipelineType/PipelineStatus/PipelineStepStatus/PipelineStepOperation/PipelinePermission enums, all Actions/Pipelines/* (dropped the redundant subfolder), the Console command, Data DTOs, the PipelineUpdated event, PipelinePolicy, and PipelineTransitions from DocumentProcessing into App\Pipelines via git mv, then a codebase-wide FQCN sweep (perl, piped through xargs — note: this repo's Bash tool runs zsh, and unquoted $var/$(...) do NOT word-split there like bash, so building a file list in a variable and passing it unquoted silently mis-splits; xargs sidesteps that).

Retry-runner inversion (the actual design decision): RetryPipelineAction previously matched on PipelineType to hardcode AudioProcessing/Dossiers job classes and per-type reset fields — a lower domain reaching into its siblings. Added App\Pipelines\Contracts\PipelineRetryRunner + App\Pipelines\Support\PipelineRetryRunnerRegistry (singleton, bound in the new PipelinesServiceProvider, which also now owns Gate::policy(Pipeline::class, ...)). Three runners — OcrPipelineRetryRunner (DocumentProcessing), AudioTranscriptionPipelineRetryRunner (AudioProcessing), DossierExportPipelineRetryRunner (Dossiers) — register themselves from their own ServiceProvider::boot(), filling the three previously-empty Retry/ directories. RetryPipelineAction now only depends on the registry.

Formalized AudioProcessing (previously entirely absent from the layering system) and Pipelines as L4 siblings in tests/Pest.php: added both to every ban list that already mentioned DocumentProcessing (Shared, Accounts, User, Tenancy, Audit, Questionnaires, ClientPortal), added new expect() rules for each, and added Pipelines to tests/Architecture/AppArchitectureTest.php's ignoring list.

Frontend: features/pipelines/types.ts and features/dossiers/types.ts aliases repointed to App.Pipelines.*; regenerated app.d.ts via `sail artisan typescript:transform`; `sail npm run types:check` (wayfinder/questionnaire/permissions/tsc) shows no new errors.

Verification: full suite via `sail artisan test` — 821/826 passing both before (git stash) and after my changes, with the exact same 4 failures + 1 error (LanguageCoverageTest, InertiaPropContractTest, WorkspaceMemberManagementTest, UniqueJobLockExpiryTest) confirmed pre-existing on develop and unrelated to Pipelines. tests/Architecture (4/4), tests/Feature/Pipelines (new location, includes RetryPipelineActionTest exercising all three runners end to end), tests/Feature/DocumentProcessing, tests/Feature/AudioRecordings, tests/Feature/Dossiers/DossierExportTest, tests/Feature/Platform/{DomainServiceProvidersTest,MorphMapTest}, tests/Feature/Tenancy/PermissionRegistryTest, tests/Unit/QueueableTagsTest all pass. Ran `sail bin pint --format agent` across every touched path.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Extracted a new App\Pipelines L4 domain from DocumentProcessing (models, actions, enums, data, event, policy, transitions, monitor command). Inverted RetryPipelineAction's hardcoded per-type dispatch into a PipelineRetryRunner contract + registry, with OCR/audio/export runners registering from their own domains. Formalized AudioProcessing and Pipelines in the Pest layering rules and architecture docs. Updated all ~70 referencing files, moved Pipeline-only tests to tests/Feature/Pipelines, regenerated frontend types. Verified via full test suite (821/826 passing, identical pre-existing failures confirmed via git-stash comparison against develop) and sail pint.
<!-- SECTION:FINAL_SUMMARY:END -->
