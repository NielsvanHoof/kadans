---
id: TASK-45
title: Split Horizon into interactive and heavy supervisors
status: Done
assignee:
  - '@claude'
created_date: '2026-08-27 17:24'
updated_date: '2026-08-27 17:37'
labels: []
dependencies: []
type: bug
ordinal: 103000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
One supervisor with balance=auto gives every queue its own process pool, and minProcesses is a per-pool floor. WorkerQueues::NAMES has five queues with minProcesses=1, so Horizon holds five workers resident even when idle, while maxProcesses=4 sits below that floor. AutoScaler::scalePool computes maxUpShift as max(0, maxProcesses - totalProcessCount), which is 0 here, so the supervisor can never add a worker under load: balance=auto, balanceMaxShift and balanceCooldown are all inert.

The memory cost is the visible symptom. Each worker is told to climb to memory=256 before recycling (maxJobs and maxTime are both 0, so the memory limit is the only recycle trigger), so steady state is 5 x 256 + 64 = ~1.34 GB. On a single 2 GiB / 1 vCPU Laravel Cloud app cluster that also runs Octane, the scheduler and two SQS long-poll daemons, that triggered a 90% memory alert on 2026-08-27.

Split the queues by cost profile instead: latency-sensitive small jobs (default, broadcasts) on one supervisor with a low memory ceiling, and provider-bound jobs (ocr, transcription, dossier-export) on another that keeps a high ceiling because GenerateDataSubjectExportJob stages an entire client archive. Give each a maxProcesses above its own pool count so autoscaling works, and add maxJobs so workers recycle on a cadence rather than at the ceiling.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Every queue in WorkerQueues::NAMES is drained by exactly one supervisor, enforced by a test rather than by reading the config
- [x] #2 Each supervisor's maxProcesses exceeds its own queue count, so maxUpShift is non-zero and autoscaling can add workers
- [x] #3 The supervisor draining dossier-export keeps a timeout above GenerateDataSubjectExportJob::TIMEOUT_SECONDS and below queue.connections.redis.retry_after
- [x] #4 Combined worker memory ceiling across both supervisors is below the previous 1280 MB
- [x] #5 Workers recycle on a job count, not only on the memory limit
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Split WorkerQueues into INTERACTIVE (default, broadcasts) and HEAVY (ocr, transcription, dossier-export); keep NAMES as their union so the existing coverage scans and csv() are unchanged.
2. Replace horizon.defaults.supervisor-1 with supervisor-interactive and supervisor-heavy; give each maxProcesses above its own pool count, add maxJobs, and keep the 630s timeout only on the heavy one.
3. Mirror both supervisors in the production/staging/local environment overrides.
4. Rewrite WorkerQueueCoverageTest so the partition (exact cover, no overlap), the maxProcesses > pool count invariant, and the dossier-export timeout window are all asserted from WorkerQueues rather than from literals.
5. Update .ai/rules/config.md, which currently says supervisors drain WorkerQueues::NAMES.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verified by WorkerQueueCoverageTest (9 tests, 40 assertions, passing). Each acceptance criterion has its own test that derives the invariant from WorkerQueues and horizon.defaults rather than asserting literals, so adding a queue or retuning a supervisor cannot pass by accident:

- exact cover / no overlap: 'the interactive and heavy groups partition every worker queue' and 'horizon supervisors drain every worker queue exactly once' (toEqualCanonicalizing + array_unique count).
- maxProcesses above the per-pool floor: 'every supervisor can still scale up from its per-pool floor', computing floor = count(queue) * minProcesses per supervisor.
- dossier-export timeout window: 'the supervisor draining dossier-export outlives the longest job but not retry_after', finding the supervisor by queue membership rather than by name.
- memory ceiling: 'the combined worker memory ceiling stays under the single-supervisor original' -- sum(maxProcesses * memory) = 1152 MB against 5 * 256 = 1280 MB.
- recycling: 'every supervisor recycles on a job count, not only on its memory limit'.

Final numbers: interactive [default, broadcasts] maxProcesses 3, memory 128, maxJobs 500, timeout 120. Heavy [ocr, transcription, dossier-export] maxProcesses 4, memory 192, maxJobs 50, timeout 630. Resident floor 896 MB (from 1344 MB), ceiling 1152 MB.

Kept balance => 'auto' on the heavy supervisor rather than collapsing it to a single ordered pool. A single pool would drain ocr first and starve a dossier export behind a 100-document upload for the better part of an hour; the per-pool floor is what guarantees each heavy queue a worker. That floor is the reason the heavy group carries 3 x 192 MB idle, which is the deliberate cost of not starving exports.

Full suite: tests/Feature/Platform + tests/Feature/DocumentProcessing + tests/Unit, 463 passed, 2557 assertions. Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Split the single Horizon supervisor into supervisor-interactive (default, broadcasts) and supervisor-heavy (ocr, transcription, dossier-export), with WorkerQueues::INTERACTIVE/HEAVY partitioning NAMES.

The bug fixed is not just memory: balance => 'auto' builds one pool per queue and minProcesses is a per-pool floor, so five queues held five workers resident while maxProcesses sat at 4 -- below that floor. AutoScaler::scalePool derives maxUpShift from max(0, maxProcesses - totalProcessCount), so it was permanently 0 and the supervisor could never scale up, with balance/balanceMaxShift/balanceCooldown all inert. Each maxProcesses is now strictly above its own queue count, enforced by a test.

Memory followed from the same config: with maxJobs and maxTime at 0, the memory limit was the only recycle trigger, making 256 MB a target every worker climbed to. maxJobs is now set on both supervisors and per-worker memory is 128/192. Resident floor 896 MB and ceiling 1152 MB, against 1344 MB fixed before, on the 2 GiB single-vCPU cluster that raised the 90% alert.

Verified by 9 tests in WorkerQueueCoverageTest asserting each invariant from config rather than literals; 463 tests pass across Platform, DocumentProcessing and Unit. .ai/rules/config.md updated with the maxProcesses floor rule and the recycle-threshold semantics.
<!-- SECTION:FINAL_SUMMARY:END -->
