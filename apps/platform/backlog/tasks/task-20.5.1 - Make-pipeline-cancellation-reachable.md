---
id: TASK-20.5.1
title: Make pipeline cancellation reachable
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 11:43'
updated_date: '2026-08-08 11:55'
labels: []
dependencies: []
parent_task_id: TASK-20.5
priority: medium
type: feature
ordinal: 72000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
PipelineStatus::Cancelled exists and is guarded against in four places — PipelineStepTransitions::ensureCanRun, PipelineTransitions::stuck, ResolveProviderPipelineForSubjectAction's open-run lookup, and PipelineStatus::isTerminal — but nothing ever assigns it. There is no cancel action, permission, route or UI.

Staff need a way to stop a run that is going nowhere without waiting for the stuck monitor, and the guards already describe the intended semantics: a cancelled run is closed, cannot advance another step, and is not a candidate for a late provider result.

The open question the implementation must answer is what happens to the subject. A cancelled OCR run leaves its UploadedDocument sitting in 'processing' forever unless cancellation also releases it, so cancellation needs the same subject-reset inversion that RetryPipelineAction uses — but landing the subject in a failed or pending state rather than re-queueing it.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 PipelineTransitions::cancel() guards that only a non-terminal run can be cancelled
- [x] #2 PipelineState exposes cancel(), locking and broadcasting like the other operations
- [x] #3 CancelPipelineAction authorizes, resets the subject so it is not left mid-flight, writes an audit event, and broadcasts
- [x] #4 A PipelinePermission::Cancel exists in the permission registry and PipelinePolicy::cancel() gates on it plus a non-terminal status
- [x] #5 A route and controller action expose it, and the pipeline show page offers a Cancel button for eligible runs
- [x] #6 Translation keys are added to both locale files so LanguageCoverageTest passes
- [x] #7 Feature tests cover: a running run cancels and releases its subject, a completed run cannot be cancelled, and a user without the permission is forbidden
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. PipelinePermission::Cancel ('pipelines.cancel'), same default roles as Retry; AuditEvent::PipelineCancelled.
2. PipelineTransitions::cancel(): guard non-terminal, set status Cancelled and last_activity_at. Mark still-pending/running steps Skipped so the timeline does not show a running step on a closed run — reuses existing vocabulary instead of adding a step status. No cancelled_at column exists, so last_activity_at carries the end time.
3. PipelineState::cancel() for the locked+broadcast path.
4. CancelPipelineAction mirroring RetryPipelineAction: own transaction and lock, reset the subject through PipelineRetryRunnerRegistry so it is not left mid-flight, audit, broadcast. Unlike retry it must NOT refuse when the subject is gone — the run still needs cancelling — and must not dispatch anything.
5. PipelinePolicy::cancel(): non-terminal status plus the permission.
6. Route (throttled, no RequirePassword, like retry), controller action, can_cancel prop on show.
7. Frontend Cancel button next to Retry, with a confirm.
8. Translation keys in both locale files.
9. Tests + full checks.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Two design calls worth recording.

What happens to still-open steps. Leaving them Running on a closed run reads wrong on the timeline, and a dedicated step status would duplicate something the vocabulary already has. PipelineTransitions::cancel() marks pending and running steps Skipped, which already means 'this stage did not happen', so a cancelled OCR run reads submit=completed, provider_processing=skipped, process_results=skipped. No new enum case.

What happens to the subject. This is the same trap RetryPipelineAction documents: every processing job claims its subject and then skips silently while that subject still looks in flight, so a cancel that only closed the run would leave the document wedged in the processing state with nothing left to move it on. CancelPipelineAction therefore calls the owning domain's PipelineRetryRunner::resetSubject() through the registry — the reset is the domain's business, not Pipelines'. Two differences from retry: it never dispatches (asserted with Queue::assertNotPushed), and a purged subject does not block it, because the run still needs closing either way.

There is no cancelled_at column and adding one was not worth a migration, so last_activity_at carries the end time and completed_at stays null, since the run did not succeed.

Housekeeping the change forced: tests/Unit/ControllerActionsTest maintains a registry of non-REST controller actions and needed 'cancel' added for PipelineController. The permission registry TypeScript and the app.d.ts types were regenerated. Eight translation keys were added to both en.json and nl.json.

Test conventions followed rather than invented: permission denial uses a Reviewer, whose role simply does not carry the permission, instead of revoking one from an Adviser; and Activity is not a TenantOwnedModel, so the audit assertion uses Activity::query()->...->sole() like RetryPipelineActionTest.

Validation: tests/Feature/Pipelines/CancelPipelineActionTest -> 12 passed. Full backend suite -> 863/868, the same 5 pre-existing failures as the clean-tree baseline. composer types:check -> 6 errors, identical to baseline. npm run test -> 363 passed (69 files, including the new cancel-pipeline-button test). npm run lint:check clean; format:check clean apart from the pre-existing dossier-show-deferred-sections.tsx, which this change never touched. Pint passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Made PipelineStatus::Cancelled reachable end to end. Added PipelineTransitions::cancel() (guards non-terminal, skips still-open steps so the timeline stays honest), PipelineState::cancel(), and CancelPipelineAction, which authorizes, releases the subject through the retry-runner registry so it is not left wedged in processing, audits via the new AuditEvent::PipelineCancelled, and broadcasts. Exposed through PipelinePermission::Cancel, PipelinePolicy::cancel(), a throttled POST route, a can_cancel prop and a confirm-dialog Cancel button, with en/nl translations. Verified with 12 new feature tests covering the closed run and skipped steps, subject release without re-queueing, a purged subject, all four already-closed statuses, the audit entry and broadcast, and the three authorization paths, plus a new frontend test for the button.
<!-- SECTION:FINAL_SUMMARY:END -->
