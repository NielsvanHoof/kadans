---
paths:
  - 'app/*/Models/**'
---

# Models

## Tenant-scoped models: extend TenantOwnedModel, fillable, casts(), no observers
Tenant-owned records extend `TenantOwnedModel`, which applies `TenantScope` globally. The scope is **soft by default** (no current tenant → `where 0 = 1`, empty results) and **strict on request paths** (workspace/portal call `TenantContext::enableStrict()` → throws). A scoping bug becomes an empty page, never a cross-tenant leak.

Mass assignment via `protected $fillable` — do not convert to `#[Fillable]` attributes.

Casts go in the `casts()` method. Enum-like values are `string()` columns cast to string-backed PHP enums — never DB `enum()` columns.

Eager-load per query with `->with()`. Do not set model-level `$with`.

Lifecycle hooks go in `booted()`. Do not add Observer classes.

New models need a morph-map alias in `AppServiceProvider::configureMorphMap()` (`enforceMorphMap` is strict) and a flat `Database\Factories\{Model}Factory`.
