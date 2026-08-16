---
id: TASK-20.4
title: Let domains describe their own pipeline subjects
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 10:59'
updated_date: '2026-08-08 11:33'
labels: []
dependencies: []
parent_task_id: TASK-20
priority: medium
type: enhancement
ordinal: 68000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
BroadcastPipelineUpdatedAction imports AudioRecording, DocumentRequest, DossierExport and UploadedDocument from Dossiers and runs an extra query to resolve dossier_id. That is exactly the per-subject knowledge the PipelineRetryRunner registry was introduced to keep out of Pipelines.

FetchPipelineShowQuery in Reporting duplicates the same branching and omits AudioRecording entirely — its loadMorph and subjectData() handle only DossierExport and UploadedDocument. An audio transcription pipeline's detail page therefore renders with a null label, context and href. That is a user-visible gap, not just a smell.

Extending the existing registry pattern closes the leak and the gap together, and stops a fourth pipeline type requiring edits inside Pipelines and Reporting.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A PipelineSubjectPresenter contract exists in Pipelines, registered per PipelineType by the owning domain from its own service provider
- [x] #2 BroadcastPipelineUpdatedAction resolves dossier context through the registry and imports no Dossiers models
- [x] #3 FetchPipelineShowQuery resolves subject label, context, href and eager loads through the registry
- [x] #4 An AudioTranscription pipeline show page renders a non-null subject label, covered by a test
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add App\Pipelines\Contracts\PipelineSubjectPresenter (type(), subjectClass(), eagerLoads(), label(), context(), dossierId(), href()) mirroring PipelineRetryRunner, plus a PipelineSubjectPresenterRegistry in Support keyed by PipelineType, registered as a singleton by PipelinesServiceProvider.
2. Implement presenters in the owning domains: UploadedDocument (DocumentProcessing), AudioRecording (AudioProcessing), DossierExport (Dossiers); register each from that domain's service provider boot().
3. Rewrite BroadcastPipelineUpdatedAction to resolve dossierId through the registry and drop every Dossiers import.
4. Rewrite FetchPipelineShowQuery::subjectData()/loadMorph to go through the registry, which closes the AudioRecording gap.
5. Registry lookups must tolerate an unregistered/missing type rather than throw — broadcasting and the show page must not hard-fail on a pipeline whose subject was deleted.
6. Test: an AudioTranscription pipeline show page renders a non-null subject label/context/href; add a DomainServiceProviders assertion that every PipelineType has a presenter.
7. Run the affected suites, types:check and pint.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
PipelineSubjectPresenter sits beside PipelineRetryRunner in Contracts and follows the same registration story: type(), subjectClass(), eagerLoads(), label(), context(), dossierId(), href(). Presenters live in the owning domains — UploadedDocumentPipelineSubjectPresenter (DocumentProcessing), AudioRecordingPipelineSubjectPresenter (AudioProcessing), DossierExportPipelineSubjectPresenter (Dossiers) — each registered from that domain's ServiceProvider::boot(). All three link to the same dossier show route, so that URL building is a DossierWorkspaceHref trait in Dossiers rather than repeated three times.

PipelineSubjectPresenterRegistry::for() returns null instead of throwing, unlike PipelineRetryRunnerRegistry::for(). Deliberate: a retry with no runner is a programming error worth surfacing loudly, but a broadcast or a show page must never hard-fail on a run whose subject was purged or whose domain has not booted. Covered by the 'still renders when the subject has been purged' test.

BroadcastPipelineUpdatedAction now imports no Dossiers models at all — it was reaching for AudioRecording, DocumentRequest, DossierExport and UploadedDocument plus an extra DocumentRequest query just to find a dossier id. FetchPipelineShowQuery lost its parallel branching and its hardcoded loadMorph list, which is what closed the AudioRecording gap by construction: audio transcription runs previously rendered with a null label, context and href because that branch simply did not exist.

One Dossiers import remains inside Pipelines, in PipelinePolicy (DossierExport plus DossierPermission for the download gate). That one is deliberate and documented in the policy's own docblock — the archive is the product of a dossier export, so it is gated on the export permission — and sits outside this task's criteria.

Registration is now covered rather than assumed: PipelineSubjectPresenterTest iterates PipelineType::cases() and fails if any type lacks a presenter, so adding a fourth type cannot silently ship an undescribed subject.

Validation: tests/Feature/Pipelines -> 89 passed (5 new). Architecture + Pipelines + DocumentProcessing + AudioRecordings + Dossiers + Reporting -> 343 passed. Full suite -> 849/854 with the same 5 pre-existing failures as the clean-tree baseline. composer types:check -> 6 errors, identical to baseline. Pint passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Introduced PipelineSubjectPresenter and its registry so each domain describes its own pipeline subject, mirroring the existing PipelineRetryRunner pattern. BroadcastPipelineUpdatedAction and FetchPipelineShowQuery now resolve label, context, href, dossier id and eager loads through the registry instead of branching on model class, which removed every Dossiers model import from BroadcastPipelineUpdatedAction and fixed the live bug where AudioTranscription runs rendered with a null subject on the show page. Lookups return null so a purged subject still broadcasts and still renders. Five new tests cover all three subject types, the purged-subject path, the broadcast dossier tag, and that every PipelineType has a presenter registered.
<!-- SECTION:FINAL_SUMMARY:END -->
