---
paths:
  - '.github/workflows/**'
---

# Workflows

## Platform CI uses setup-vp
platform.yml quality and coverage jobs use voidzero-dev/setup-vp@v1.18.0 (exact tag, never @v1) with working-directory: apps/platform and vp install --frozen-lockfile. The action ignores defaults.run. Leave infra-drift.yml on setup-node; Pulumi is not Vite+.

## postgres-migrations CI service must use the pgvector image
The `postgres` service in platform.yml's `postgres-migrations` job must be `pgvector/pgvector:pg18`, matching `compose.yaml`. Do not swap it back to `postgres:*-alpine`: `Schema::ensureVectorExtensionExists()` in 2026_09_07_100000_add_search_columns_to_uploaded_documents_table emits `create extension if not exists "vector"`, which fails with SQLSTATE 0A000 ("extension is not available") on an image that does not ship pgvector — `if not exists` only skips an already-installed extension, not an unavailable one. There is no way to install it at runtime in a GHA service container.

`pg_trgm` is unaffected because it ships in postgres-contrib. The SQLite suite never catches this: the vector branch is behind an `isPostgres()` guard, so only this job surfaces it.

## The suite runs once; coverage is merged from the shards
Platform CI runs the PHP suite exactly once. The four time-balanced Pest shards each write `--coverage-php` to `coverage-shards/shard-N.cov`, upload it, and the `coverage-gate` job merges them via `scripts/coverage-gate.php --min=87 --expect=4`. Do not add a job that runs the whole suite again to measure coverage — that is what the old 8m43s `coverage` job did, and it doubled the suite for a number the shards already contain. Verified equivalent: merged shards report 87.85%, the unsharded `--coverage --min=87` run reports `Total: 87.8 %` (Pest rounds to 1dp). Both use `percentageOfExecutedLines` over phpunit.xml `<source>`.

Notes for anyone touching it:
- A `.cov` is NOT a serialized CodeCoverage object. Since php-code-coverage 14 it is serialization format 3, a plain array — merge through `Serialization\Merger` + `Report\Facade::fromSerializedData()`, which also refuses to merge shards from different PHP versions, drivers or commits. `require`-ing one and expecting an object silently gets you an array.
- `--coverage-php` activates collection WITHOUT rendering the per-file table, so shards pay for pcov but not the report. Do not add `--coverage` to the shard command.
- `--expect=4` is load-bearing: a missing shard artifact would otherwise read as a real coverage regression. An empty file list exits 1 rather than passing vacuously.
- Job graph: `build` (thin, Vite artifact only) → `tests` (4 shards) → `coverage-gate`; `quality` (PHP static gates via `composer ci:static`), `frontend` (types/lint/frontend coverage) and `postgres-migrations` run in parallel. `tests` must depend on `build`, never on `quality` — the shards need the Vite manifest (tests/TestCase.php pins built-manifest mode), not the static gates.
- `composer ci:quality` is now local-only (`ci:check`); CI calls `ci:static` plus explicit npm steps so `npm run build` and `npm run test:coverage` each run exactly once per run.
- PHP setup + Composer download-cache + install live in `.github/actions/setup-platform-php`. It caches Composer's cache dir, not `vendor/`, because restoring a populated vendor/ skips post-autoload-dump and therefore `artisan package:discover`. A composite action does not inherit `defaults.run.working-directory`, so it takes it as an input.
