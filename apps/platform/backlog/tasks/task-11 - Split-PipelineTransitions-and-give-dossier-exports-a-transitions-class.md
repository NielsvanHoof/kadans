---
id: TASK-11
title: Split PipelineTransitions and give dossier exports a transitions class
status: Done
assignee:
  - '@niels'
created_date: '2026-08-06 09:54'
updated_date: '2026-08-07 08:25'
labels: []
dependencies: []
references:
  - app/DocumentProcessing/Transitions/PipelineTransitions.php
  - app/Dossiers/Actions/GenerateDossierExportAction.php
  - app/Dossiers/Actions/MarkDossierExportFailedAction.php
  - app/DocumentProcessing/Actions/Pipelines/RetryPipelineAction.php
documentation:
  - docs/backend.md
priority: medium
type: enhancement
ordinal: 39000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
PipelineTransitions is two state machines fused into one 306-line class. It exposes a duplicated API surface - step-level and pipeline-level variants of the same three verbs, fail and failPipeline, stuck and stuckPipeline, heartbeat and heartbeatPipeline. The suffix is the tell.

The same file also holds fourteen quiet-save or logging-disabled calls, more than the rest of the application combined - the next highest file has two. So every pipeline state change is invisible to the activity log, in a product whose selling point is an audit trail for accountancy firms. That may well be a deliberate volume decision, but it is undocumented and reads as an oversight.

Separately, dossier exports are a state machine with four write sites and no transitions class, unlike dossiers, document requests and pipelines. One of those four writes is in a different domain entirely - the pipeline retry action resets the export status directly.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Step-level and pipeline-level transitions are separate classes with non-duplicated verbs
- [x] #2 The activity-log exclusion for pipeline state is either removed or documented with its rationale
- [x] #3 A DossierExportTransitions class exists and all four export status writes go through it
- [x] #4 No domain outside Dossiers writes the export status directly
- [x] #5 Existing pipeline and export tests pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Split app/Pipelines/Transitions/PipelineTransitions.php into:
   - PipelineTransitions (pipeline-only): retry, fail (was failPipeline), stuck (was stuckPipeline), heartbeat (was heartbeatPipeline), private markFailed/markStuck.
   - New PipelineStepTransitions (step-level): start, complete, skip, fail, stuck, heartbeat (operate on Pipeline+PipelineStep), private ensureCanRun/ensureStepIsRunning/advance. Delegates to injected PipelineTransitions for fail/stuck pipeline-side effects.
2. Add rationale docblocks explaining the saveQuietly() usage: Pipeline/PipelineStep/DossierExport models do not use LogsActivity, so these writes were never activity-logged; meaningful audit events are captured explicitly via LogAuditActivityAction at the action layer (RetryPipelineAction, GenerateDossierExportAction, MarkDossierExportFailedAction) instead of via model events, and internal step/heartbeat bookkeeping is high-frequency and not user-meaningful.
3. Update PipelineStateAction to inject both classes and route step-key operations through PipelineStepTransitions and no-step operations through PipelineTransitions with renamed methods.
4. Create app/Dossiers/Transitions/DossierExportTransitions.php (start/complete/fail/retry) mirroring DossierTransitions/DocumentRequestTransitions conventions, and route all 4 export status writes through it:
   - GenerateDossierExportAction (claim -> Processing, success -> Completed)
   - MarkDossierExportFailedAction (-> Failed)
   - DossierExportPipelineRetryRunner::resetSubject (-> Pending)
5. Update tests/Feature/Pipelines/PipelineTransitionsTest.php to use both PipelineTransitions and PipelineStepTransitions.
6. Run pint and the affected Pest suites (PipelineTransitionsTest, DossierExportTest, and any OCR/Audio pipeline tests exercising PipelineStateAction/RetryPipelineAction).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Split app/Pipelines/Transitions/PipelineTransitions.php (pipeline-only: retry/fail/stuck/heartbeat) from new app/Pipelines/Transitions/PipelineStepTransitions.php (step-level: start/complete/skip/fail/stuck/heartbeat), removing the failPipeline/stuckPipeline/heartbeatPipeline duplicate-verb surface. PipelineStateAction now injects both and routes step-key ops to PipelineStepTransitions and no-step ops to PipelineTransitions. Documented saveQuietly() rationale in class docblocks: Pipeline/PipelineStep/DossierExport do not use LogsActivity so these writes were never activity-logged; meaningful business events are logged explicitly via LogAuditActivityAction at the action layer. Created app/Dossiers/Transitions/DossierExportTransitions.php (canStart/start/complete/fail/retry) and routed all four export status writes through it: GenerateDossierExportAction (start, complete), MarkDossierExportFailedAction (fail), DossierExportPipelineRetryRunner::resetSubject (retry, now resolved via container in DossiersServiceProvider instead of 'new' since it has a constructor dependency). Verified: grep confirms no DossierExportStatus:: writes outside app/Dossiers and no failPipeline/stuckPipeline/heartbeatPipeline references remain anywhere. Pint clean, PHPStan level 8 clean on all changed/new files. Pest: PipelineTransitionsTest + RetryPipelineActionTest + DossierExportTest + PipelineTest + PipelineUpdatedBroadcastTest + OCR/audio pipeline job tests = 116/116 passing. Full suite run: 4 pre-existing failures + 1 pre-existing error, confirmed unrelated by reproducing them on a stash of the clean tree (translation coverage, an Inertia dossier-index prop contract fixture, workspace member management, and a queue unique-job timeout property — none touch pipeline/export transitions).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Split PipelineTransitions into pipeline-level (PipelineTransitions: retry/fail/stuck/heartbeat) and step-level (new PipelineStepTransitions: start/complete/skip/fail/stuck/heartbeat) classes, eliminating the fail/failPipeline, stuck/stuckPipeline, heartbeat/heartbeatPipeline duplication, and documented the saveQuietly() activity-log decision on both classes. Added app/Dossiers/Transitions/DossierExportTransitions.php and routed all four dossier-export status writes (GenerateDossierExportAction's start/complete, MarkDossierExportFailedAction's fail, DossierExportPipelineRetryRunner's retry) through it; confirmed via grep that no export status write exists outside the Dossiers domain. Verified with Pest (116 targeted tests passing, full suite shows only pre-existing unrelated failures) and PHPStan level 8 (0 errors) on all touched files.
<!-- SECTION:FINAL_SUMMARY:END -->
