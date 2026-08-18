---
id: TASK-9.2
title: >-
  Invert the pipeline retry dispatch so DocumentProcessing stops importing other
  domains
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:53'
updated_date: '2026-08-06 16:10'
labels: []
dependencies: []
references:
  - app/DocumentProcessing/Actions/Pipelines/RetryPipelineAction.php
  - app/DocumentProcessing/Enums/PipelineType.php
modified_files:
  - app/DocumentProcessing/Contracts/PipelineRetryRunner.php
  - app/DocumentProcessing/Support/PipelineRetryRunnerRegistry.php
  - app/DocumentProcessing/Actions/Pipelines/OcrDocumentPipelineRetryRunner.php
  - app/DocumentProcessing/Actions/Pipelines/RetryPipelineAction.php
  - app/AudioProcessing/Actions/AudioTranscriptionPipelineRetryRunner.php
  - app/Dossiers/Actions/DossierExportPipelineRetryRunner.php
  - app/DocumentProcessing/DocumentProcessingServiceProvider.php
  - app/AudioProcessing/AudioProcessingServiceProvider.php
  - app/Dossiers/DossiersServiceProvider.php
parent_task_id: TASK-9
priority: high
type: enhancement
ordinal: 31000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The retry-pipeline action matches on the pipeline type three separate times: once to reset subject attributes per type, once for the export status, and once to build the job to dispatch. Adding a fourth pipeline type means editing one file in three places.

More importantly this is the reason DocumentProcessing has to import the AudioProcessing job and the Dossiers export job - it is the action that creates two of the cross-domain edges blocking the layering rule.

Invert it: a runner contract owned by DocumentProcessing, one implementation per pipeline type registered by the domain that owns that pipeline, resolved through a map. All three matches collapse and all the imports point the right way.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Adding a new pipeline type requires no change to the retry action
- [x] #2 DocumentProcessing no longer imports jobs from AudioProcessing or Dossiers
- [x] #3 Each pipeline type registers its runner from its own domain service provider
- [x] #4 Retry behaviour is unchanged for all three existing pipeline types, covered by tests
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add DocumentProcessing\Contracts\PipelineRetryRunner (type, subjectClass, assertSubject, resetSubject, dispatch).
2. Add PipelineRetryRunnerRegistry resolving runners by PipelineType from tagged services.
3. Implement OcrDocumentPipelineRetryRunner (DocumentProcessing), AudioTranscriptionPipelineRetryRunner (AudioProcessing), DossierExportPipelineRetryRunner (Dossiers).
4. Tag/bind from each domain ServiceProvider; DocumentProcessing registers the registry.
5. Slim RetryPipelineAction to use registry only — no Audio/Dossiers job imports.
6. Existing RetryPipelineActionTest must stay green.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Inverted pipeline retry so DocumentProcessing no longer imports AudioProcessing or Dossiers jobs.

## Changes
- PipelineRetryRunner contract + PipelineRetryRunnerRegistry
- OcrDocument / AudioTranscription / DossierExport runners registered from each domain service provider
- RetryPipelineAction delegates resolve/reset/dispatch to the registry

## Tests
RetryPipelineActionTest: 16 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
