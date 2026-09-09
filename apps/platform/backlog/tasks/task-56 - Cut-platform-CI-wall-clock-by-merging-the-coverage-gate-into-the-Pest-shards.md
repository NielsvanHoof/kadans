---
id: TASK-56
title: Cut platform CI wall-clock by merging the coverage gate into the Pest shards
status: In Progress
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-09 12:22'
updated_date: '2026-09-09 12:46'
labels:
  - ci
  - performance
dependencies: []
ordinal: 120000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Platform CI runs the PHP suite twice. The four `tests` shards cover it once (225 files, ~302s of work total per tests/.pest/shards.json), then the `coverage` job runs the entire suite again unsharded under pcov — that job was observed at 8m43s and is the critical path. On top of that, `npm run build` and `npm run test:coverage` each run twice per CI run (once inside `composer ci:quality`, once again in the `coverage` job), and no job caches Composer dependencies, so `composer install` runs uncached seven times per run.

The `tests` job depends on `quality` only because `quality` happens to be the job that uploads the Vite build artifact. The shards need the artifact, not the static gates, so a dedicated build job lets the shards, the gates and the coverage gate all start at once. The artifact itself cannot be avoided: tests/TestCase.php pins Vite to built-manifest mode, so the suite needs a real public/build/manifest.json.

Chosen direction (user picked the most aggressive of four options): stop running the suite twice by emitting per-shard coverage from the existing four shards and merging it in a small gate job, rather than keeping a separate full-suite coverage run.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The PHP suite runs exactly once per CI run; there is no separate unsharded full-suite coverage job
- [ ] #2 The 87% PHP line coverage floor is still enforced and still fails the run when unmet, measured over the merged coverage of all four shards
- [ ] #3 npm run build and npm run test:coverage each run exactly once per CI run
- [ ] #4 Composer dependencies are cached across every job that runs composer install
- [ ] #5 The Pest shards no longer wait on the static quality gates; they start from a dedicated build artifact job
- [ ] #6 No new Composer or npm dependency is added without explicit approval
- [ ] #7 Coverage merging uses a committed, reviewable script rather than an undocumented inline shell pipeline
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented. Suite now runs once: four Pest shards each emit --coverage-php, coverage-gate merges them via scripts/coverage-gate.php.

Verified equivalence of the coverage metric: merged shards report 87.85% (14556/16570 executable lines); the previous unsharded 'pest --parallel --coverage --min=87' reports 'Total: 87.8 %'. Same metric (percentageOfExecutedLines over phpunit.xml <source>), Pest just rounds to 1dp.

Gate exit codes verified locally: 4 shards @ min 87 -> 0; no files -> 1; 3 of 4 shards -> 1; min 99 -> 1; nonexistent path -> 1; unexpanded glob literal -> 1.

Key discovery: a .cov file is not a serialized CodeCoverage object. php-code-coverage 14 uses serialization format 3 (a plain array), so the merge goes through the library's own Serialization\\Merger + Report\\Facade::fromSerializedData(). No new dependency was added - both ship with phpunit.

Also refreshed tests/.pest/shards.json: it was stale (Pest warned 238 files vs the 225 recorded), now 238 files / 281.2s / ~70.3s per shard.

Out of scope, found while verifying: 'composer ci:static' fails on this branch because phpstan reports 4 nullsafe.neverNull errors in app/Tenancy/Data/Settings/TenantAiSettingsData.php lines 28-31. Untouched by this task and pre-existing. Deliberately not fixed: the naive '?->' to '->' change would break the documented 'firm with no settings row yet gets the shipped defaults' behaviour, so it needs a real decision, not a lint-silencing edit.

Fixed the pre-existing phpstan blocker as well (was previously flagged as out of scope, on a mistaken reading).

Correction: I first claimed removing '?->' would break the 'no settings row' default. That was wrong. '??' uses isset semantics and short-circuits property access on a null left-hand side, so $null->anything ?? 'x' yields 'x' with no error - verified directly. PHPStan was right and the nullsafe was genuinely redundant.

Changed the 4 lines in app/Tenancy/Data/Settings/TenantAiSettingsData.php from '?->' to '->' and extended the docblock so the next reader (or agent) does not revert it into a phpstan failure.

fromModel() had no test at all, so added tests/Unit/Models/TenantAiSettingsDataTest.php (3 tests, 12 assertions), placed in Unit/Models because it needs the app for Eloquent casts but not the database. It locks the real risk of this change: that a stored 'false' could fall through '??' to the enabled default. It does not - Eloquent's __isset() reports a non-null attribute as set, so only a missing or null attribute defaults to true.

Verification: phpstan 'No errors'; pint PASS (1084 files); composer ci:static fully green; 41 tests pass across every consumer of the mapper (WorkspaceSettings, DocumentOverviewTenantSettings, DocumentSearch, FirmPortalSessionWindow, FirmRetentionWindow). shards.json refreshed again for the added file: 239 classes, ~69.8s per shard.
<!-- SECTION:NOTES:END -->
