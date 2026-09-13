---
id: TASK-58
title: Close the remaining queue and job-locking gaps
status: Done
assignee: []
created_date: '2026-09-13 10:07'
updated_date: '2026-09-13 10:44'
labels: []
dependencies: []
priority: high
type: bug
ordinal: 122000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
An audit of all seven ShouldBeUnique jobs against a queue-locking checklist found the job classes themselves clean (uniqueId, uniqueFor, afterCommit, retry_after ordering all correct) but three gaps around them: every scheduled command takes the 24-hour default scheduler mutex, the data-subject export is the only long-running worker without an atomic claim, and the boot-time guard that keeps retry_after above every job timeout enumerates jobs by hand. Each is latent rather than firing today, which is why none of them surfaced in TASK-35.3, TASK-36.3 or TASK-6.1.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 No scheduled command relies on the default 1440-minute withoutOverlapping expiry
- [x] #2 Every long-running worker claims its subject atomically before doing work
- [x] #3 The retry_after boot guard cannot silently miss a newly added job
- [x] #4 All seven ShouldBeUnique jobs are covered by the uniqueFor invariant test
<!-- AC:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
All three subtasks done. 58.1 bounded 16 scheduler mutexes that were taking the 24-hour default. 58.2 gave the data-subject export the atomic claim its two siblings already had, via a fulfilment_started_at timestamp with a self-healing stale window. 58.3 collapsed two drifting hand-maintained job lists into one registry behind a TimeBoundedJob contract, guarded against the filesystem, and documented the uniqueFor/retry_after ordering as deliberate. Full suite 1830 passed / 14179 assertions; each new guard verified negatively by reintroducing the defect it is meant to catch.
<!-- SECTION:FINAL_SUMMARY:END -->
