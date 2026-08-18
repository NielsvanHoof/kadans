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
