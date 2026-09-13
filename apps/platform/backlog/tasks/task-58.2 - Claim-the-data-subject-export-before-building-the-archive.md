---
id: TASK-58.2
title: Claim the data-subject export before building the archive
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-13 10:07'
updated_date: '2026-09-13 10:34'
labels: []
dependencies: []
parent_task_id: TASK-58
priority: high
type: bug
ordinal: 124000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
GenerateDataSubjectExportAction checks isOpen() read-only, then spends up to 600s zipping and streaming to S3, then marks the request Completed at the very end. There is no window where the row says in-progress, so two concurrent executions both pass the check. Its two siblings both claim properly: GenerateDossierExportAction and AnonymizeDataSubjectAction each open a transaction, lockForUpdate, re-check, and transition before any work. TASK-36.3 locked the anonymization and left the export behind. ShouldBeUnique does not close this: it only blocks new dispatches, so it cannot stop Redis re-reserving the job once retry_after (660s) elapses, against a 630s worker timeout on a job that is entirely stream-bound. Damage is bounded (the archive path is deterministic, so a double run overwrites) but it means doubled S3 egress and two Completed audit entries on a GDPR record.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The export claims the request atomically before building anything, and the claim is visible to a second run
- [x] #2 A second concurrent execution returns without building or storing an archive
- [x] #3 A build that dies uncleanly becomes reclaimable on its own, without an operator unsticking a row
- [x] #4 A build that fails cleanly releases its claim immediately so a retry is not made to wait out the stale window
- [x] #5 A test proves the second of two concurrent runs is a no-op
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Migration: nullable fulfilment_started_at on data_subject_requests (portable across Postgres and SQLite).
2. Model: fillable, datetime cast, property docblock.
3. DataSubjectRequestTransitions: add claimForFulfilment() next to lockIfOpen(), as a single conditional UPDATE keyed on status = pending plus a stale cutoff. The claim doctrine stays in the transitions class.
4. Size the stale window as GenerateDataSubjectExportJob::uniqueFor() so the claim and the unique lock expire on the same reasoning rather than two independent magic numbers.
5. GenerateDataSubjectExportAction: claim first, release the claim in the failure path so a clean failure is retryable at once.
6. Test the concurrent no-op, the stale reclaim, and the release-on-failure in tests/Feature/Retention/DataSubjectExportTest.php.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Design confirmed with the user before implementing: timestamp claim, status untouched. A Processing enum case was the alternative and was rejected — it contradicts the pending->completed|refused terminal flow DataSubjectRequestTransitions records, drags in customer-facing copy and the Inertia prop contract fixture, and wedges on an unclean death rather than self-healing.

Claim lives in DataSubjectRequestTransitions next to lockIfOpen() as claimForFulfilment(), a single conditional UPDATE on (status = pending AND (fulfilment_started_at IS NULL OR <= cutoff)). No row lock is held, which is the whole point: lockIfOpen works for the erasure only because that fits in one transaction, and pinning a Postgres connection for a ten-minute S3 stream is not a trade worth making on a burstable instance.

Stale window is GenerateDataSubjectExportJob::lifecycleSeconds() (2250s), extracted from uniqueFor() so the unique lock and the claim are sized by one formula instead of two magic numbers. The job computes the cutoff and passes it to the action, keeping the dependency direction Job -> Action.

Caught during the first test run: releasing the claim inside the existing catch block was wrong. That block opens with Storage::disk($disk)->exists(), which throws on a misconfigured disk and skips the release entirely — the claim then leaks for the full 2250s and the operator retry silently no-ops, which is the exact failure the claim exists to prevent. Extracted buildAndRecord() so the release is the first statement of an outer catch covering everything after the claim, including the Client findOrFail that used to sit outside any try.

Test call sites: 11 across 4 files now pass dataSubjectExportStaleCutoff(), a new tests/Pest.php helper that mirrors what the job passes, so the tests exercise the real claim rather than a convenient default. The production signature stays required — no optional parameter a future caller could forget.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
GenerateDataSubjectExportAction now claims the request before it builds anything, instead of checking isOpen() read-only and marking Completed ten minutes later. The claim is a nullable fulfilment_started_at column taken by one conditional UPDATE in DataSubjectRequestTransitions::claimForFulfilment(), with the stale window sized as GenerateDataSubjectExportJob::lifecycleSeconds() (2250s) so an unclean death self-heals, and releaseFulfilmentClaim() in an outer catch covering everything after the claim so a clean failure is retryable at once. Status semantics are untouched: pending -> completed|refused still holds, no enum case, no customer-facing copy.

Verified with three new tests in tests/Feature/Retention/DataSubjectExportTest.php (second worker mid-build builds nothing; a claim older than the job lifecycle is reclaimed unaided; a failure hands the claim back and the immediate retry completes). Full run: tests/Feature/Platform + tests/Feature/Retention 337 passed / 1367 assertions. The concurrency test was confirmed to bite by reverting the claim to the old isOpen() check, which fails it. Pint clean on all touched files; PHPStan reports only a pre-existing AWS SDK typing error in AmazonTranscribeAudioTranscriber.php, untouched here.
<!-- SECTION:FINAL_SUMMARY:END -->
