---
paths:
  - 'tests/**'
---

# Tests

## Workspace helpers are mandatory; RefreshDatabase is wired in Pest.php
Never declare `RefreshDatabase` in a test file — `tests/Pest.php` applies it per directory (Feature → TestCase + RefreshDatabase; `Unit/Models` → TestCase only, no DB; rest of `Unit/` → pure PHP).

For any Feature test needing a tenant, the `tests/Pest.php` helpers are mandatory: `provisionWorkspace()`, `actingInWorkspace()`, `workspaceMember()`, `workspaceRoute()`, `grantDossierBuilderLock()`. Do **not** hand-roll `ProvisionTenantAction`, `TenantMembership::query()->create(...)`, `->makeCurrent()`, `setPermissionsTeamId()`, or `withSession(['active_tenant_id' => …])` — the helpers already set the in-process current tenant.

Pest `test()` over `it()`. No PHPUnit class-based tests. Use `describe()` to group transition/state suites (see Pipelines and DocumentRequests); do not wrap every file in `describe()`.

Use `->with([...])` datasets for role matrices, enum/status tables, and validation case tables that only differ by inputs — named keys (`'read only' => Role::ReadOnly`). Do **not** dataset unique happy paths or multi-step workflows. Exemplar: `tests/Feature/Pipelines/PipelinePolicyTest.php`.

Add `covers(SomeClass::class)` (or `mutates()`) at the top of focused Feature/Unit files that target a single class — policies, transitions, actions, queries, mappers. Skip it on broad HTTP/Inertia flow files that touch many classes.

Mutation testing is **local only** (not a CI gate): `composer test:mutate` mutates each `covers()` file in isolation so other targets are not scored as UNCOVERED. Needs PCOV or Xdebug. Close untested mutants with stronger assertions; `@pest-mutate-ignore` only for intentional noise with a reason. Do **not** add `--mutate` to `ci:check`.

CI runs PHP Pest in **4 time-balanced shards** (`tests/.pest/shards.json`). Refresh with `composer test:shards:update` when the suite shape changes a lot. Local TIA (`pest()->tia()->locally()->baselined()`) is enabled when a git work tree is available — Sail mounts `apps/platform` without the monorepo `.git`, so TIA stays off there unless you run Pest on the host. Never pass `--tia` to PR CI; the `tia-baseline.yml` workflow records the shared graph.

Assertions: `assertInertia` with component + prop checks for pages; `assertJsonPath` or small `assertJson([...])` for JSON. Do **not** use `AssertableJson` or `assertJsonFragment`.

A domain's policy, query, and transition tests are Feature tests (they need the DB) and live in `tests/Feature/{Domain}/`. `tests/Architecture/` is only for pure `arch()`/`expect()` checks that never boot the app — anything calling `$this->seed()`, `config()`, or a factory belongs in `tests/Feature/Platform/`.

Authorization coverage means all three: happy path, forbidden role, and owner vs read-only divergence.

Mock third-party SDKs with Mockery; never mock your own Actions or Queries in Feature tests.

## Mutate covers() files per file
Focused policy/transition/action/query/mapper tests declare `covers(Class)`. Run `composer test:mutate` (per-file loop over `covers()` tests) so other covers() targets are not scored as UNCOVERED. Mutation stays local; do not add `--mutate` to `ci:check`.

## CI shards and local TIA
Commit `tests/.pest/shards.json` and refresh with `composer test:shards:update`. Platform CI runs `php vendor/bin/pest --parallel --ci --shard=N/4` (not `artisan test --parallel --shard` under Pest 5). Enable `pest()->tia()->locally()->baselined()` only when a git work tree exists (Sail has none). PR CI must stay full-suite (no `--tia`). Shared baseline comes from `.github/workflows/tia-baseline.yml` (`pest-tia-baseline` artifact).
