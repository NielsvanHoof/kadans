---
id: TASK-58.3
title: Derive the job-timeout guard and cover every unique job
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-13 10:07'
updated_date: '2026-09-13 10:44'
labels: []
dependencies: []
parent_task_id: TASK-58
priority: medium
type: chore
ordinal: 125000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two hand-maintained lists have drifted from the seven queued jobs, plus one undocumented ordering. ProductionConfigurationValidator::largestJobTimeoutSeconds() names four timeouts and misses AnonymizeDataSubjectJob, ApplyTextractResultJob and ApplyTranscribeResultJob. The number it returns is still 600 today because the anonymize timeout happens to match the export one, so this is latent, but that function is the only thing between a mis-set REDIS_QUEUE_RETRY_AFTER and two workers concurrently running a GDPR erasure. UniqueJobLockExpiryTest asserts the uniqueFor formula for three of the seven unique jobs; the four uncovered include the 2250s erasure lock, the longest-lived in the system. Separately, uniqueFor (460s) sits below retry_after (660s) for the three pipeline jobs, so after an unclean worker death the lock expires ~200s before Redis re-reserves the original and a manual retry can overlap the resurrected job. claim() catches that today, so the numbers are fine, but nothing records that the ordering is deliberate and someone will eventually invert it and wedge subjects.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The retry_after guard derives its job timeouts rather than listing classes by hand
- [x] #2 Adding a queued job without updating the guard cannot weaken it
- [x] #3 The uniqueFor invariant test covers all seven ShouldBeUnique jobs and extends without a per-job match arm
- [x] #4 The uniqueFor-below-retry_after ordering is documented where a future reader will hit it
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add app/Shared/Contracts/Queue/TimeBoundedJob with static timeoutSeconds() and lifecycleSeconds(), so both hand-maintained lists can ask every job the same question. ProcessAudioRecordingJob is the reason a static accessor beats reading #[Timeout] by reflection: its timeout comes from config at construction so Octane cannot cache it on the class.
2. All seven queued jobs implement it; uniqueFor() becomes a delegation to lifecycleSeconds().
3. Add WorkerJobs::NAMES next to WorkerQueues as the single registry, mirroring the WorkerQueues drain contract.
4. ProductionConfigurationValidator::largestJobTimeoutSeconds() maps over the registry instead of naming four classes.
5. Guard the registry against the filesystem the way WorkerQueueCoverageTest guards the queue list, so a new job that is not registered fails CI rather than weakening the boot check.
6. Rewrite UniqueJobLockExpiryTest over the registry, dropping the three match arms.
7. Record the uniqueFor-below-retry_after ordering in config/queue.php next to the retry_after comment that already explains the other half.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Chose a static contract over reflecting #[Timeout] because ProcessAudioRecordingJob deliberately has no such attribute — it reads transcription.timeouts.job in the constructor so an Octane worker cannot cache a stale value on the class. An attribute-only reader would have scored it zero, which is the same quiet under-count the task exists to fix, just relocated.

Static rather than instance accessors so a caller can ask a class-string. Jobs take scalar IDs, so an instance-based reader would mean inventing fixture arguments inside ProductionConfigurationValidator.

uniqueFor() on all seven now delegates to lifecycleSeconds(). That is what lets UniqueJobLockExpiryTest drop its three match arms and run over WorkerJobs::NAMES: the new per-job assertion builds the expected number from timeoutSeconds/MAX_TRIES/BACKOFF_SECONDS and compares, and a second test instantiates each job without its constructor to prove uniqueFor() really returns it. ProcessAudioRecordingJob passing that with an uninitialised $timeout is the proof the delegation is real rather than incidental.

Registry guard mirrors WorkerQueueCoverageTest deliberately — the queue list is guarded against the onQueue() literals on disk, so the job list is guarded against the job classes on disk. Verified negatively: removing AnonymizeDataSubjectJob from NAMES fails WorkerJobCoverageTest by name.

Item 5 (uniqueFor 460s below retry_after 660s) is documented in config/queue.php rather than changed. Raising uniqueFor to cover retry_after would re-introduce the 20-minute wedge after an OOM that TASK-6.1 bounded uniqueFor to prevent; claim() is the real mutual exclusion and the unique lock only suppresses duplicate dispatches. The comment says so explicitly so the ordering is not "corrected" later.

Checks: Pint clean, Rector clean (it chained two Pest expect() calls in the new tests, applied), PHPStan reports only the pre-existing AWS SDK typing error in AmazonTranscribeAudioTranscriber.php line 58, which predates this work and is untouched.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Replaced both hand-maintained job lists with one registry. Added App\Shared\Contracts\Queue\TimeBoundedJob (static timeoutSeconds/lifecycleSeconds), implemented on all seven queued jobs with uniqueFor() delegating to lifecycleSeconds(), and App\Platform\Support\Observability\WorkerJobs::NAMES as the single list. ProductionConfigurationValidator::largestJobTimeoutSeconds() now maps over the registry instead of naming four of seven classes, and UniqueJobLockExpiryTest runs over it instead of three match arms, so the 2250s erasure lock is asserted for the first time. The uniqueFor-below-retry_after ordering is documented in config/queue.php as deliberate, with the reason not to invert it.

Verified: new WorkerJobCoverageTest (3 tests) guards the registry against app/*/Jobs on disk the way WorkerQueueCoverageTest guards the queue list, and was confirmed to bite by removing AnonymizeDataSubjectJob from NAMES. UniqueJobLockExpiryTest is 15 passing tests across all seven jobs. Full suite: 1830 passed / 14179 assertions. Pint and Rector clean; PHPStan reports only a pre-existing AWS SDK typing error in AmazonTranscribeAudioTranscriber.php that is unrelated to this change.
<!-- SECTION:FINAL_SUMMARY:END -->
