# Adding a domain

Use **Dossiers** as the reference for a full CRUD domain. Use **Reporting** as the reference for a thin, read-mostly domain that reuses other models.

## Checklist (full domain)

Copy this and tick as you go.

### Backend code

- [ ] Create `app/{Domain}/` with the folders you need:
  - `Models/`, `Policies/`, `Enums/`
  - `Actions/`, `Queries/`, `Data/`
  - `Http/Controllers/`, `Http/Requests/`
  - Optional: `Jobs/`, `Transitions/`, `Services/`, `Ai/`, `Console/Commands/`, `Listeners/`
- [ ] Namespace everything under `App\{Domain}\...`
- [ ] Tenant-owned models extend `TenantOwnedModel` (or use `BelongsToTenant` and related Tenancy concerns)
- [ ] Keep controllers thin: authorize → Action/Query → Inertia response (see [Backend](backend.md))

### Permissions

- [ ] Add `Enums/{Domain}Permission.php` implementing `TenantPermission` (see [Permissions](permissions.md))
- [ ] Register the enum in [`config/tenant-permissions.php`](../config/tenant-permissions.php)
- [ ] Add a `PermissionGroup` case if no existing group fits
- [ ] Policy uses `AuthorizesTenantPermission`
- [ ] Run `vendor/bin/sail artisan tenancy:sync-roles`

### Composition root wiring

- [ ] Create `app/{Domain}/{Domain}ServiceProvider.php` when the domain has policies or container bindings
- [ ] `Gate::policy(Model::class, Policy::class)` in that domain service provider’s `boot()`
- [ ] Register the provider in [`bootstrap/providers.php`](../bootstrap/providers.php)
- [ ] Morph map alias for the model in [`AppServiceProvider`](../app/Providers/AppServiceProvider.php) (stable string keys — do not rename lightly)
- [ ] If you add Artisan commands: put them under `app/{Domain}/Console/Commands` and add that path to `withCommands([...])` in [`bootstrap/app.php`](../bootstrap/app.php)
- [ ] Listeners under `app/{Domain}/Listeners` are auto-discovered — no extra registration

### Routes

- [ ] Add `routes/tenant/{domain}.php` (or `routes/web/...` / extend `portal.php` for guest surfaces)
- [ ] `require` the new file from the parent route file ([`routes/tenant.php`](../routes/tenant.php) for workspace staff)
- [ ] Point controllers at `App\{Domain}\Http\Controllers\...`
- [ ] Prefer named routes; frontend imports Wayfinder helpers from `@/actions` / `@/routes` after generate

### Persistence

- [ ] Migration(s) in `database/migrations/`
- [ ] Factory in `database/factories/`
- [ ] Seeder only if the domain needs system defaults (keep seeders central)

### Frontend

- [ ] Thin pages: `resources/js/pages/{slug}/`
- [ ] Feature UI + `types.ts`: `resources/js/features/{slug}/`
- [ ] Do not put domain types in `@/types`
- [ ] Shared `components/` must not import from `features/`
- [ ] Follow [Frontend](frontend.md) and import rules in [`vite.lint.ts`](../vite.lint.ts)

### Tests

- [ ] Feature tests under `tests/Feature/{Domain}/`
- [ ] Use `provisionWorkspace()` and other helpers from `tests/Pest.php` (see [Testing](testing.md))
- [ ] Cover authorization (allowed + forbidden roles) and happy paths
- [ ] Run:

```bash
vendor/bin/sail artisan test --compact tests/Feature/{Domain}/
```

## Minimal “Reporting-style” domain

When you only need screens over existing models:

- [ ] `Http/`, `Queries/`, `Data/` (and maybe `Actions/`)
- [ ] Routes + Inertia pages/features
- [ ] Skip new models, permission enums, and policies **unless** you introduce new abilities
- [ ] Still authorize using the owning domain’s policies / permissions where appropriate

## Do not invent parallel systems

- Reuse Tenancy for tenant scoping and permissions — do not add a second permission store
- Register policies and domain bindings in the domain’s `*ServiceProvider`; keep the morph map in `AppServiceProvider`
- Keep migrations/factories in `database/` — not inside `app/{Domain}`

## Smoke path after wiring

1. Migrate (if you added tables)
2. `tenancy:sync-roles`
3. Hit the new routes as an owner and as a role that should be denied
4. Run the new feature tests
5. `vendor/bin/sail bin pint --dirty --format agent` for PHP style
