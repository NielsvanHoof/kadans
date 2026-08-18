---
id: TASK-9.4
title: Narrow the blanket Laravel arch preset ignore
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:53'
updated_date: '2026-08-06 16:14'
labels: []
dependencies: []
references:
  - tests/Architecture/AppArchitectureTest.php
  - tests/Pest.php
documentation:
  - docs/testing.md
parent_task_id: TASK-9
priority: low
type: chore
ordinal: 33000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The Pest Laravel arch preset is called with an ignoring list containing every domain folder in the application, so it effectively only inspects app/Providers. The custom project preset re-adds most of what it would check - suffixes, handle methods, finality, layering - so little is actually lost, but the call reads as active coverage across the whole application and is not.

Either narrow the ignore to the specific rules that genuinely conflict with project conventions, or drop the call and document that the custom preset supersedes it.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The preset either inspects the domain folders or is removed
- [x] #2 Any remaining ignore names the specific conflicting rule rather than a whole namespace
- [x] #3 The testing doc states which preset is authoritative
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Read Pest Laravel.php preset rules and compare to qompose custom preset (already covers suffixes, handle, finality, controller base).
2. Temporarily remove the blanket domain ignoring list from AppArchitectureTest and run arch tests to collect concrete conflicts.
3. Choose approach: (A) drop laravel() if qompose+php+security supersede it, or (B) keep laravel() with ignores scoped to specific conflicting rules/classes — not whole domain namespaces.
4. Update docs/testing.md Architecture tests section to state which preset is authoritative.
5. Re-run AppArchitectureTest; finalize ACs.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Chose removal over narrow ignore: Pest laravel preset expectations target App\Models, App\Jobs, App\Console\Commands, etc.; ignoring() applies across the whole preset, so naming a 'specific conflicting rule' without whole namespaces is not supported.

Verified arch suite green with php + security + qompose only.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
- Removed Pest `arch()->preset()->laravel()` and its blanket domain `ignoring([...])` list from AppArchitectureTest.
- Kept `php` + `security` + custom `qompose` (authoritative for domain conventions and layering).
- Documented in docs/testing.md why laravel is unused (classic App\Models layout vs App\{Domain}).

## Why remove instead of narrow
The laravel preset hard-codes paths like App\Models, App\Jobs, App\Http\Controllers. Per-rule ignores are not available on the preset API, so the only honest options were whole-namespace ignores (what we had) or dropping the preset.

## Tests
- vendor/bin/sail artisan test --compact tests/Architecture/AppArchitectureTest.php → 3 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
