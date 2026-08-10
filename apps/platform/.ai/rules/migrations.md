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
