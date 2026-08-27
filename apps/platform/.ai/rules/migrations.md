---
paths:
  - 'database/migrations/**'
---

# Migrations

## constrained() foreign keys, string columns for enums, no foreignIdFor
Use `$table->foreignId('…')->constrained()` with `cascadeOnDelete`/`nullOnDelete` as needed. Do not use `foreignIdFor`.

Enum-like values are `string()` columns cast to string-backed PHP enums in the model — never DB `enum()` columns.

Tenant-owned children that unique on `(tenant_id, parent_id)` need a composite foreign key.

Migrations run against PostgreSQL in production and CI (a dedicated CI job tests migrations plus one rollback) but SQLite in the test suite — keep them portable across both.

## Partial indexes need raw SQL
Laravel 13 PostgresGrammar::compileIndex() does not emit WHERE, so Blueprint->index()->where() creates a full index. Write partial indexes with DB::statement('create index ... where ...'). SQLite accepts the same SQL, which this project's tests require.

## Tenant-leading indexes for default list sorts
List/archive default sorts need tenant-leading composites on the ORDER BY column: dossiers (updated_at, deleted_at), clients (name, deleted_at), pipelines (created_at), document_requests media library (type, updated_at). (tenant_id, status, created_at) does not serve ORDER BY created_at without a status predicate. Do not recreate Spatie's roles/model_has_* team_foreign_key prefix indexes — they are exact prefixes of larger indexes on the same table.

## Measure an index against Postgres before adding it
Four indexes were proposed from reading query predicates; two did not survive EXPLAIN ANALYZE against seeded Postgres and were dropped. Reasoning about predicates is not enough — the planner has options you did not consider.

Two traps that produced false positives here:
- "A composite index cannot serve a predicate that omits its leading column" is false on Postgres. It bitmap-scans the composite instead. A standalone `activity_log.created_at` for `activitylog:clean` measured no better than the existing `(tenant_id, created_at)`.
- An `OR` branch the index cannot cover makes the planner ignore it entirely. `pipelines (tenant_id, status, last_activity_at)` was never chosen, because the stuck-detection query ORs in a `started_at` fallback.

Seed realistic volume *and* realistic selectivity — an unselective predicate seq-scans no matter what, so a badly-shaped fixture will tell you an index is useless when it is not. Record the before/after numbers in the migration docblock, as 2026_08_26_084300 and 2026_08_26_084917 do.

Trigram/GIN migrations must guard on `DB::connection()->getDriverName() === 'pgsql'`: the suite runs SQLite :memory: with SCOUT_DRIVER=collection.
