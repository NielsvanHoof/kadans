---
id: TASK-20.5.2
title: Write next_retry_at and pick up due runs
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 11:43'
updated_date: '2026-08-08 12:00'
labels: []
dependencies: []
parent_task_id: TASK-20.5
priority: medium
type: feature
ordinal: 73000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
next_retry_at has a column, a fillable entry, a cast and a field on PipelineShowData, so it reaches the generated TypeScript and the frontend, but it is only ever set to null — in PipelineTransitions::retry(). Nothing schedules anything.

Today a failed run waits for a human to press Retry. The column was clearly intended to carry an automatic backoff, and the pieces it needs already exist: attempt_number is cumulative, and PipelineRetryRunnerRegistry already knows how to reset a subject and re-queue a run per pipeline type.

Scope is automatic retry of failed runs on an exponential backoff, with a cap so a permanently broken run stops being retried and stays visible as failed for a human to look at.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A failed run records next_retry_at using an exponential backoff derived from attempt_number, with a documented cap on automatic attempts
- [x] #2 A scheduled command picks up failed runs whose next_retry_at is due and re-queues them through PipelineRetryRunnerRegistry, per tenant
- [x] #3 Runs past the automatic-attempt cap are left failed with next_retry_at null so manual retry is still the escape hatch
- [x] #4 The command is registered in routes/console.php alongside the other scheduled work
- [x] #5 Feature tests cover: backoff is written on failure, a due run is re-queued, a not-yet-due run is left alone, a capped run is not retried, and counting is per tenant
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add a config block (config/pipelines.php) for the backoff: base delay, multiplier, max automatic attempts. Register it so it is tunable per environment rather than hardcoded.
2. PipelineTransitions::fail() writes next_retry_at when attempt_number is below the cap, and leaves it null at or above the cap so a permanently broken run stays failed for a human.
3. New pipelines:retry-due command: per tenant, find failed runs with next_retry_at due, and re-queue each through the same path RetryPipelineAction uses. Extract the shared reset+dispatch so manual and automatic retry cannot drift.
4. Schedule it in routes/console.php next to pipelines:monitor.
5. Automatic retries must be distinguishable from manual ones in the audit log (no causer, or a distinct event).
6. Tests: backoff written on failure, capped run gets null, due run re-queued, not-yet-due run untouched, per-tenant scoping (terminating on toBase(), not getQuery()).
7. Full checks.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Config lives in a new config/pipelines.php (retry.max_attempts, base_delay_minutes, multiplier, max_delay_minutes, batch_size), matching how ocr.php / exports.php / retention.php are handled, so the backoff is tunable per environment instead of hardcoded. Defaults: 3 automatic attempts, 5 minutes base, x4, capped at 4 hours — so 5, 20, then stop.

PipelineRetryBackoff owns the arithmetic and, importantly, the cap decision: nextRetryAt() returns null once attempt_number reaches max_attempts. PipelineTransitions::fail() writes whatever it returns, which means a run that has spent its budget lands as failed with next_retry_at null and stays visible for a human. Manual Retry is unaffected and remains the escape hatch.

The command re-queues through RetryPipelineAction rather than reimplementing the reset. That was the main design call: the subject reset is the thing that is easy to forget and silently fatal (every processing job skips its work while the subject still looks in flight), so automatic and manual retry must not have two copies of it. RetryPipelineAction::handle()'s $user became nullable to make this possible; a null causer is exactly what marks an attempt as automatic, and the audit properties carry an explicit automatic flag as well. includeRequestContext is off for automatic retries since there is no request.

The due query terminates on the Eloquent builder via ->get(), and building stays on getQuery(), so TenantScope applies — the trap TASK-20.1 recorded. The per-tenant test would catch a leak: two tenants with one due run each must report 2, not 4.

A run that cannot be retried (subject purged, for example) is logged and counted as skipped rather than aborting the batch, so one bad row does not strand every other tenant's due runs.

Scheduled every five minutes with withoutOverlapping, next to pipelines:monitor.

Validation: tests/Feature/Pipelines/RetryDuePipelinesCommandTest -> 8 passed, covering the backoff curve and ceiling, the write on failure, the cap, a due run re-queued with attempt_number incremented and next_retry_at cleared, a not-yet-due run untouched, a capped run left alone, the causer-less audit entry, and per-tenant counting. Full backend suite -> 871/876, the same 5 pre-existing failures as the clean-tree baseline. composer types:check -> 6 errors, identical to baseline. Pint passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Gave next_retry_at a real writer and a consumer. PipelineTransitions::fail() now books the next attempt through a new PipelineRetryBackoff (exponential on attempt_number, delay-capped, and null once the automatic-attempt budget is spent so a permanently broken run stays failed for a human). A new scheduled pipelines:retry-due command picks up due runs per tenant and re-queues them through RetryPipelineAction itself rather than a second copy of the subject-reset logic, with a nullable causer marking the attempt as automatic in the audit log. Backoff is configurable via config/pipelines.php. Verified with 8 new tests covering the curve, the cap, due and not-yet-due runs, the automatic audit entry, and per-tenant scoping.
<!-- SECTION:FINAL_SUMMARY:END -->
