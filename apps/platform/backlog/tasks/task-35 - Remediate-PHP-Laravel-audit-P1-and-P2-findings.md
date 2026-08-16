---
id: TASK-35
title: Remediate PHP/Laravel audit P1 and P2 findings
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-13 11:32'
updated_date: '2026-08-14 10:40'
labels:
  - audit
milestone: m-0
dependencies: []
references:
  - ~/.claude/plans/https-laravel-com-docs-13-x-helpers-intr-nested-brooks.md
  - .ai/rules/app.md
  - .ai/rules/document-processing-audio-processing.md
priority: high
type: task
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Parent for the verified PHP-only audit of apps/platform covering performance, Laravel/PHP best practice, and SOLID. Implement the P1 defects and the contained OCP enum-behaviour cleanup that already matches PipelineStatus::isTerminal().

Posture: full findings were reported; this parent is the approved implementation set only. Domain layering, Action/Query shape, Model::shouldBeStrict(), PHPStan level 8, and related mechanical guarantees are already in place — do not reopen those.

Constraints for every subtask: do not share abstractions between DocumentProcessing and AudioProcessing (.ai/rules/app.md). TranscriptionStatus already lives in AudioProcessing (TASK-9.1) — do not move it again. Preferred commit order is the subtask ordinals (indexes first, then data-loss, then config, concurrency, cheap correctness, Scout, enums).

Source: PHP/Laravel audit plan (https-laravel-com-docs-13-x-helpers-intr-nested-brooks).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Each P1 and P2 subtask is independently shippable and revertable
- [x] #2 No new shared abstraction is introduced between DocumentProcessing and AudioProcessing
- [x] #3 Durable decisions from the work are recorded with Boost record-rule, not only in code comments
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Work subtasks in ordinal order as independently revertable commits:
1. TASK-35.1 indexes (migration only)
2. TASK-35.2 audio purge
3. TASK-35.3 retry_after + validator
4. TASK-35.4 owner lock
5. TASK-35.5 notification 404 + bell skip
6. TASK-35.6 Scout bound
7. TASK-35.7 enum behaviour

Constraints: no DocumentProcessing/AudioProcessing shared abstraction; TranscriptionStatus already in AudioProcessing (TASK-9.1); record durable decisions with Boost record-rule. Finalize each subtask before starting the next.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
All 7 subtasks (35.1-35.7) finalized as independent commits in ordinal order: indexes, audio purge, retry_after, owner lock, notification 404, Scout bound, enum status sets.

Parent-level verification: vendor/bin/sail artisan test --compact tests/Unit -> 73 passed, 1017 assertions. This surfaced one regression left behind by 35.5's notification work: ControllerActionsTest still declared App\\Platform\\Http\\Controllers\\WorkspaceNotificationController after the controller moved to the WorkspaceNotifications domain, failing both the identical-map assertion and the class_exists check. Fixed the import; suite green.

AC #2 holds: no shared abstraction was introduced between DocumentProcessing and AudioProcessing. Note the parent description's claim that TranscriptionStatus lives in AudioProcessing is stale - it is at app/DocumentProcessing/Enums/TranscriptionStatus.php, which is what .ai/rules/requests-enums-policies.md now records.

AC #3: durable enum-status-set decision recorded in .ai/rules/requests-enums-policies.md (exhaustive isX() match plus *Values() from cases(); do not widen Failed-only retry or Stuck-only health checks).
<!-- SECTION:NOTES:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: audit-intake
created: 2026-08-13 11:35
---
Subtask commit order (independently revertable): TASK-35.1 indexes → TASK-35.2 audio purge → TASK-35.3 retry_after → TASK-35.4 owner lock → TASK-35.5 notification 404 + bell arm → TASK-35.6 Scout bound → TASK-35.7 enum behaviour. TranscriptionStatus move is already Done as TASK-9.1 — skipped.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Remediated the P1/P2 PHP/Laravel audit findings across 7 independently revertable subtasks: missing list/cleanup indexes, audio-recording object-storage purge on legal delete, queue retry_after raised above every job timeout, workspace last-owner lock, notification 404 + unmapped bell items omitted, bounded Scout key hydration with tenant-filtered audit search, and status sets moved onto DocumentRequestStatus/DossierStatus/PipelineStatus as exhaustive matches. Verified with vendor/bin/sail artisan test --compact tests/Unit (73 passed, 1017 assertions) plus each subtask's own feature tests; the parent run caught and fixed a stale WorkspaceNotificationController namespace in ControllerActionsTest left by 35.5. Enum-status-set convention recorded in .ai/rules/requests-enums-policies.md.
<!-- SECTION:FINAL_SUMMARY:END -->
