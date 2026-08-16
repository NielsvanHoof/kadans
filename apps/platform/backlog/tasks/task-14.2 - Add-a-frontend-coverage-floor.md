---
id: TASK-14.2
title: Add a frontend coverage floor
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:10'
labels: []
dependencies: []
references:
  - vitest.config.ts
  - package.json
parent_task_id: TASK-14
priority: medium
type: enhancement
ordinal: 51000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The coverage provider is installed but the Vitest config has no coverage block and no thresholds, so the frontend has no floor at all while the PHP suite is gated at 87 percent.

Generated output must be excluded or the number is meaningless: the Wayfinder actions and routes folders, the transformer-generated types, and the vendored shadcn primitives.

Set the initial threshold at whatever the suite currently achieves once the shared-component tests land, so it ratchets rather than blocking.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Vitest enforces a line coverage threshold
- [x] #2 Generated and vendored directories are excluded from the measurement
- [x] #3 The threshold runs in CI and fails the build when coverage drops
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Inspect existing Vitest coverage output with exclusions for generated/vendored paths.
2. Add coverage block to vitest.config.ts (provider v8, exclude actions/routes/generated/ui shadcn, thresholds).
3. Set line threshold at current measured floor (ratchet, not aspirational).
4. Wire CI/script so coverage runs and fails on drop (npm script + CI workflow if present).
5. Document briefly in docs/testing.md if that file already covers Vitest.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Measured line coverage ~36.5% with the exclude set; floored at 36 to avoid flake. Raise when adding meaningful tests.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added a Vitest line-coverage floor so frontend coverage cannot silently regress.

- `vitest.config.ts`: v8 coverage with include `resources/js/**/*.{ts,tsx}`; excludes Wayfinder actions/routes/wayfinder, transformer types, feature generated files, vendored `components/ui`, and test harness.
- Threshold: lines ≥ 36% (measured ~36.5% after shared-trunk tests).
- `npm run test:coverage`; `composer ci:check` and the Platform CI coverage job both run it.
- Documented in `docs/testing.md`.

Evidence: `vendor/bin/sail npm run test:coverage` — 360 tests passed, lines 36.47%, exit 0.
<!-- SECTION:FINAL_SUMMARY:END -->
