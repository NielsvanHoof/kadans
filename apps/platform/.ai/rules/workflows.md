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
