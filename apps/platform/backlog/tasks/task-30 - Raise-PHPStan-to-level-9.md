---
id: TASK-30
title: Raise PHPStan to level 9
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-08 13:36'
updated_date: '2026-08-11 10:08'
labels: []
milestone: m-0
dependencies: []
references:
  - phpstan.neon
priority: low
type: chore
ordinal: 83000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Static analysis sits at level 8 of 10 with strict rules, larastan, banned-code, `checkModelProperties`, and `reportUnmatchedIgnoredErrors` all on — a strong configuration one notch below the top. Level 9 mainly tightens `mixed` handling, which is exactly where a `$request->validated()`-shaped codebase accumulates quiet imprecision.

Pure polish, and last in the v1.0 order: it should not delay anything above it. If the level-9 delta turns out to be large or forces ignores that weaken the config more than the level gains, closing the task by recording that finding is a legitimate outcome — the point is to have measured it deliberately rather than to have stopped at 8 by default.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 PHPStan runs clean at level 9, or the attempt is recorded with the specific error classes that make it not worth taking and the level stays at 8
- [x] #2 No new baseline file or blanket ignore is introduced to reach the level
- [x] #3 `composer types:check` passes in CI at whatever level is chosen
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Bump phpstan.neon level from 8 to 9 temporarily and run composer types:check via Sail.
2. If clean (or small targeted fixes without baseline/blanket ignores): keep level 9 and verify types:check.
3. If large mixed/type delta or would need weakening ignores: revert to 8 and record the specific error classes/counts in the task.
4. Finalize with whichever level is chosen.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Level 9 attempt: 211 errors / 105 files. Dominated by cast.string, cast.int, argument.type from mixed. Staying at 8 is the recorded outcome; no phpstan.neon change.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Measured PHPStan level 9 deliberately and kept level **8**. The level-9 delta is too large to land without a broad typing campaign or config weakening.

## Measurement
- `vendor/bin/sail bin phpstan analyse --level=9`: **211 errors** across **105 files**.
- Dominant identifiers: `cast.string` (94), `cast.int` (52), `argument.type` (48), plus smaller counts (`assignOp.invalid`, `method.nonObject`, offset/encapsed/array/return issues).
- Pattern matches the TASK-30 expectation: level 9 tightens `mixed` from config/session/`validated()`-shaped code.

## Outcome
- `phpstan.neon` stays at `level: 8`.
- No baseline file and no new blanket ignores introduced.
- `vendor/bin/sail composer types:check` passes at level 8.
<!-- SECTION:FINAL_SUMMARY:END -->
