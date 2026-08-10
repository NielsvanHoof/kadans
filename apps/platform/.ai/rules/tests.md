---
paths:
  - 'tests/**'
---

# Tests

## Workspace helpers are mandatory; RefreshDatabase is wired in Pest.php
Never declare `RefreshDatabase` in a test file — `tests/Pest.php` applies it per directory (Feature → TestCase + RefreshDatabase; `Unit/Models` → TestCase only, no DB; rest of `Unit/` → pure PHP).

For any Feature test needing a tenant, the `tests/Pest.php` helpers are mandatory: `provisionWorkspace()`, `actingInWorkspace()`, `workspaceMember()`, `workspaceRoute()`, `grantDossierBuilderLock()`. Do **not** hand-roll `ProvisionTenantAction`, `TenantMembership::query()->create(...)`, `->makeCurrent()`, `setPermissionsTeamId()`, or `withSession(['active_tenant_id' => …])` — the helpers already set the in-process current tenant.

Pest `test()` over `it()`. No PHPUnit class-based tests.

Assertions: `assertInertia` with component + prop checks for pages; `assertJsonPath` or small `assertJson([...])` for JSON. Do **not** use `AssertableJson` or `assertJsonFragment`.

A domain's policy, query, and transition tests are Feature tests (they need the DB) and live in `tests/Feature/{Domain}/`. `tests/Architecture/` is only for pure `arch()`/`expect()` checks that never boot the app — anything calling `$this->seed()`, `config()`, or a factory belongs in `tests/Feature/Platform/`.

Authorization coverage means all three: happy path, forbidden role, and owner vs read-only divergence.

Mock third-party SDKs with Mockery; never mock your own Actions or Queries in Feature tests.
