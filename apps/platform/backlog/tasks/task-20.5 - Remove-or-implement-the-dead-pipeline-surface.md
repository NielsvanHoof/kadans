---
id: TASK-20.5
title: Remove or implement the dead pipeline surface
status: Done
assignee: []
created_date: '2026-08-08 10:59'
updated_date: '2026-08-08 12:00'
labels: []
dependencies: []
parent_task_id: TASK-20
priority: low
type: chore
ordinal: 69000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two pieces of the pipeline model describe capability the system does not have.

next_retry_at has a column, a fillable entry, a cast and a field on PipelineShowData, so it reaches the generated TypeScript and the frontend. It is only ever set to null, in PipelineTransitions::retry(). Nothing writes a value to it.

PipelineStatus::Cancelled is guarded against in four places but never assigned. There is no cancel action, route or UI.

Decide for each: implement backoff-scheduled retry and pipeline cancellation, or drop them. Leaving them advertises behaviour that does not exist.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Pipeline cancellation is implemented end to end (TASK-20.5.1)
- [x] #2 next_retry_at is written by a real backoff-scheduled retry path (TASK-20.5.2)
- [x] #3 Neither next_retry_at nor PipelineStatus::Cancelled remains write-only or unreachable
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
User decision (asked because implement-vs-delete is a product call, not a cleanup): implement both rather than delete. next_retry_at gets a real backoff-scheduled retry path and PipelineStatus::Cancelled gets a reachable cancel action.

Split into TASK-20.5.1 (cancellation) and TASK-20.5.2 (backoff retry) because each is a focused PR on its own. Cancellation spans transitions, the permission registry, policy, route, controller, audit event, frontend button and translations. Backoff retry spans the failure-path write, a scheduled command and the retry-runner registry. Original ACs were replaced to point at the two subtasks.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Resolved by implementing rather than deleting, per the user's decision. PipelineStatus::Cancelled is now reachable end to end through CancelPipelineAction and a Cancel button (TASK-20.5.1), and next_retry_at is written by a real exponential-backoff path that the new pipelines:retry-due command consumes (TASK-20.5.2). Neither is write-only or unreachable any more.
<!-- SECTION:FINAL_SUMMARY:END -->
