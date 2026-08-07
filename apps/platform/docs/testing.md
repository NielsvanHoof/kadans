# Testing conventions

How automated tests are written and organized. For where tests live in the repo layout, see [Architecture](architecture.md).

## Framework

Write tests with **Pest** using `test()` functions. Prefer `test()` over `it()` unless you are extending a file that already uses `it()` consistently.

Do not add PHPUnit class-based tests.

## Suite layout

```
tests/
├── Feature/{Domain}/   # HTTP, Inertia, authorization, integration — includes
│                       # policy, query, and transition coverage (needs the DB)
├── Unit/               # Pure logic only — no DB, cache, or HTTP. Models/ gets
│                       # the Laravel app for Eloquent but still no database.
├── Architecture/       # Pure arch()/expect() rule checks — no app boot at all
├── fixtures/           # Shared test assets
└── Pest.php            # Suite wiring and helpers
```

Feature tests for a domain belong under `tests/Feature/{Domain}/`, including that domain's policy, query, and transition tests — they need `RefreshDatabase`, which makes them Feature tests, not Unit. Cross-cutting concerns that touch the framework but aren't tied to one domain (Inertia prop contracts, readiness checks, shared Inertia data, config/service-provider/model-registration checks) live in `tests/Feature/Platform/`. Portal and other cross-cutting flows may use their own sibling folders (`Portal`, `Localization`, etc.).

`tests/Architecture/` is reserved for tests built entirely on Pest's `arch()`/`expect()` DSL (see `AppArchitectureTest.php`) — they inspect code structure and never need `TestCase` or a database. A test that calls `$this->seed()`, hits `config()`/`env()`, or uses a factory needs the Laravel app and belongs in `Feature/Platform/` instead, even if it's checking something architectural in spirit.

## Database reset

Do not declare `RefreshDatabase` in individual Feature test files. Suite wiring in [`tests/Pest.php`](../tests/Pest.php) applies it:

- Feature tests (including domain policy/query/transition tests) → `TestCase` + `RefreshDatabase`
- `Unit/Models` → `TestCase` only (no DB)
- Everything else in `Unit/` → pure PHP, no database, no framework — if a test needs the app container at all, it doesn't belong in `Unit/`

## Factories and seeders

Create test-owned records with Eloquent factories from `database/factories/`. Factories live in a flat `Database\Factories\{Model}Factory` namespace — not under domain folders. Discovery is configured in `AppServiceProvider`.

Use seeders for shared reference data:

- `RolesAndPermissionsSeeder` — roles and tenant permissions
- `SystemQuestionnaireTemplateSeeder` — system questionnaire templates

Prefer `$this->seed()` or `provisionWorkspace()` over hand-rolled role rows.

## Workspace helpers

For tenant/workspace Feature tests, use the helpers defined in `tests/Pest.php`:

| Helper | Purpose |
|--------|---------|
| `provisionWorkspace($name, $owner)` | Seed roles, create tenant + owner membership |
| `actingInWorkspace($tenant)` | Set current tenant and session context |
| `workspaceMember($tenant, $role)` | Create a user with a given role in the tenant |
| `workspaceRoute($name, $tenant, $params)` | Build a named workspace route with tenant slug |
| `grantDossierBuilderLock(...)` | Dossier builder lock for editor tests |

These are mandatory for every Feature test that needs a tenant, full stop — do not hand-roll `ProvisionTenantAction`, `TenantMembership::query()->create(...)`, raw `->makeCurrent()`/`setPermissionsTeamId()`, or `withSession(['active_tenant_id' => …])`. Routes built with `workspaceRoute()` resolve the tenant from the `{tenant}` slug in the URL; `provisionWorkspace()`/`actingInWorkspace()`/`workspaceMember()` already set the in-process current tenant, so an explicit session write is never needed.

## Authorization coverage

When testing a domain endpoint, cover:

- Happy path for a role that should succeed
- Forbidden response for a role that should be denied
- Owner vs read-only vs domain-specific roles where policies differ

## Assertions

**Inertia pages** — use `assertInertia` with component name and prop checks.

**JSON endpoints** — use `assertJsonPath` or small `assertJson([...])` arrays. Do not use `AssertableJson` or `assertJsonFragment`.

Example:

```php
$response->assertInertia(fn ($page) => $page
    ->component('dossiers/show')
    ->has('dossier')
);

$response->assertJsonPath('data.id', $dossier->id);
```

## Mocking and fakes

- **Mockery** — mock third-party SDK and HTTP clients (AWS, OCR, transcription). Do not mock your own Action or Query classes in Feature tests.
- **Facade fakes** — use `Storage::fake()`, `Queue::fake()`, `Notification::fake()`, `Event::fake()` to isolate framework side effects.
- **Integration by default** — Feature tests hit real routes, middleware, and database unless an external service must be stubbed.
- **Floci** — Sail's local AWS emulator is optional for demos. Pest keeps `OCR_DRIVER=mock` / `TRANSCRIPTION_DRIVER=mock` and does not require Floci.

## Frontend unit tests

Vitest tests live in a `tests/` subfolder next to what they cover, mirroring the tested file's path rather than sitting beside it — `features/<domain>/tests/...`, `hooks/tests/...`, `lib/tests/...`, `components/<group>/tests/...`. Run with `npm test`.

CI runs `npm run test:coverage`, which enforces the line-coverage floor in `vitest.config.ts`. Generated Wayfinder/transformer output and vendored `components/ui` primitives are excluded from the measurement. Raise the threshold when coverage improves; do not lower it to greenwash a drop.

Vitest covers hooks, formatters, and isolated components. HTTP and Inertia contracts belong in Pest Feature tests, not Vitest.

Use the shared mock factories in `resources/js/test/mocks.ts` instead of hand-rolling `usePage()`/`useTranslation()` mocks per file:

```ts
vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@inertiajs/react', () => ({ usePage: () => mockInertiaPage({ locale: 'en' }) }));
```

## Architecture tests

Domain layering (which domain may depend on which) is enforced in `tests/Pest.php` and checked by Architecture tests. When you add cross-domain usage, respect the layer order documented in [Architecture](architecture.md).

## Running tests

Run the smallest set that covers your change:

```bash
# One domain
vendor/bin/sail artisan test --compact tests/Feature/Dossiers/

# One file or filter
vendor/bin/sail artisan test --compact tests/Feature/Dossiers/StaffCrudTest.php
vendor/bin/sail artisan test --compact --filter=creates_dossier

# Frontend unit
npm test

# Frontend unit + coverage floor (same gate as CI)
npm run test:coverage
```

After PHP changes, run Pint on dirty files:

```bash
vendor/bin/sail bin pint --dirty --format agent
```

## Related

- [Backend](backend.md) — Actions, Queries, policies under test
- [Frontend](frontend.md) — Vitest vs Pest split
- [Adding a domain](adding-a-domain.md) — test checklist for new features
- [Permissions](permissions.md) — how to test allowed and forbidden roles
