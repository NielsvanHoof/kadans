---
id: TASK-58.1
title: Bound every scheduler mutex so a killed run cannot go dark for a day
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-13 10:07'
updated_date: '2026-09-13 10:10'
labels: []
dependencies: []
parent_task_id: TASK-58
priority: high
type: bug
ordinal: 123000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
All 17 withoutOverlapping() calls in routes/console.php are argument-less, so they take Laravel default of 1440 minutes. The mutex is released in the command finally block, so a clean run is fine, but a SIGKILL, an OOM, or a Cloud deploy that terminates the scheduler mid-run leaves the key behind and the command stops running for 24 hours with no error raised anywhere. The worst case is pipelines:retry-due, an every-five-minutes command: a day without automatic pipeline retries, while pipelines:monitor (same risk) stops marking runs stuck, so nothing detects the silence. This is the scheduler form of the expireAfter rule the jobs already follow.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Every Schedule::command withoutOverlapping call passes an explicit expiry
- [x] #2 Each expiry is sized to the commands realistic runtime, not a shared constant
- [x] #3 A test fails if a scheduled command is registered without an explicit mutex expiry
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Give every withoutOverlapping() in routes/console.php an explicit expiry sized to that command realistic runtime and its own cadence.
2. Add a guard to tests/Feature/Platform/ScheduleTest.php (alongside the existing onOneServer sweep) that fails when any scheduled event still carries the framework default.
3. Prove the guard bites by reverting one line and watching it fail.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Corrected the count from the audit: 16 withoutOverlapping() calls, not 17 (grep -c counted a comment line in the nightly block).

Narrowed the risk while reading the framework: ManagesAttributes::withoutOverlapping($expiresAt = 1440, $releaseOnTerminationSignals = true) already releases the mutex in the run finally and on SIGTERM, so a clean run and an ordinary Cloud deploy both clean up. The exposure is SIGKILL and the OOM killer only. Recorded that in the file comment so nobody widens the expiries again on the strength of the deploy case.

Expiries: 5 for the every-minute health commands and horizon:snapshot, 10 for the two every-five-minute pipeline sweeps, 30 for the hourly ones and the two queue prunes, 60 for the nightly model/notification/conversation prunes, 90 for retention:purge-legal, 120 for the weekly activity-log prune, 15 for the local-only telescope:prune. Each stays inside its own cadence so a stale key can never skip more than one occurrence of an hourly-or-faster command.

Guard verified negatively: setting model:prune back to withoutOverlapping() fails the new test with the command name in the message.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Gave all 16 withoutOverlapping() calls in routes/console.php an explicit expiry (5-120 minutes, each sized to that command runtime and kept inside its own cadence) instead of the framework 1440-minute default, and documented in the file that the exposure is SIGKILL/OOM only since Laravel already releases the mutex on SIGTERM. Added an every-scheduled-command sweep to tests/Feature/Platform/ScheduleTest.php next to the existing onOneServer one. Verified: 11 passed / 64 assertions, and the guard was confirmed to bite by reverting model:prune to the default, which failed with the command named in the message.
<!-- SECTION:FINAL_SUMMARY:END -->
