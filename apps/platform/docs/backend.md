# Backend conventions

How PHP code is organized and written in this application. For the big picture (domains, routes, layering), see [Architecture](architecture.md).

## Domain layout

Organize code by domain under `app/{Domain}/`. There is no top-level `app/Http` — HTTP lives inside each domain.

A full feature domain typically has:

```
app/{Domain}/
├── Actions/           # writes — one class per use case
├── Queries/           # reads — index/show payloads
├── Data/              # Spatie Data DTOs for Inertia and JSON
├── Enums/             # string-backed enums; *Permission implements TenantPermission
├── Http/
│   ├── Controllers/
│   └── Requests/
├── Models/
├── Policies/
├── Jobs/              # optional
├── Transitions/       # optional — guarded status changes
├── Services/          # optional — infrastructure drivers only
├── Console/Commands/  # optional — register path in bootstrap/app.php
├── Listeners/         # optional — auto-discovered
└── {Domain}ServiceProvider.php
```

Thin domains (Reporting, WorkspaceNotifications) may only need `Http/`, `Queries/`, `Data/`, and sometimes `Actions/` when they reuse another domain's models.

Register policies and container bindings in the domain's `*ServiceProvider`. Add the provider to `[bootstrap/providers.php](../bootstrap/providers.php)`. Keep app-wide defaults (morph map, factory discovery) in `AppServiceProvider`.

## Controllers

Keep controllers thin:

1. Authorize (`$this->authorize(...)`)
2. Call an Action or Query `handle()`
3. Return `Inertia::render()`, a redirect, a download, or `response()->json()`

Use multi-method controllers with explicit verb routes. Do not use `Route::resource` or `apiResource`. Assign middleware on route groups in `routes/*.php`, not via `HasMiddleware` or `#[Middleware]` on controllers.

Prefer implicit route model binding (type-hinted models in controller signatures). Use `queryWithoutTenantScope()` for intentional cross-tenant lookups — not `withoutGlobalScopes()`.

## Actions, Queries, and Services

**Actions** handle mutations and multi-step writes. Invoke them with `handle()`. Do not use `execute` or `__invoke` for app Actions.

**Queries** handle reads and page payloads. Invoke them with `handle()`. Do not add a repository layer or expose `builder(): Builder` from Query classes.

For filterable workspace index lists, extend `Shared\Queries\PaginatedIndexQuery` and use length-aware `paginate()`.

**Services** are for infrastructure drivers and orchestrators (OCR, transcription, readiness probes). Do not use Services as a substitute for HTTP use-case Actions.

**Transitions** encode guarded status changes (dossiers, document requests, pipelines). Put status rules there instead of scattering updates across controllers.

## Data objects

Shape Inertia and XHR payloads as `final` classes extending Spatie `Data` under `app/{Domain}/Data/`. Add TypeScript attributes as elsewhere in the codebase so types can be generated for the frontend.

Reserve plain `final readonly` classes for internal-only value objects that are never sent to the client.

Do not add Eloquent API Resources. Return JSON with `response()->json([...])` built from Spatie Data (`fromModel` / `toArray`).

## Validation

Validate HTTP writes with Form Request classes under `app/{Domain}/Http/Requests/`. Use the `LocalizesValidationAttributes` trait for attribute labels. Do not validate inline in controllers.

Fortify contracts and console commands may use inline validation; that is the exception, not the pattern for domain HTTP.

## Authorization

Authorize with Policy classes registered via `Gate::policy()` in the owning domain's `*ServiceProvider`. Policies use `AuthorizesTenantPermission`.

- Call `$this->authorize()` in controllers.
- Use `$user->can()` in Form Request `authorize()` and when building Inertia capability props.

See [Permissions](permissions.md) for how tenant permissions are defined and synced.

## Models

Declare mass assignment with `protected $fillable`. Do not convert to `#[Fillable]` attributes.

Define casts with the `casts()` method. Store enum-like values as `string()` columns in migrations and cast to string-backed PHP enums in the model. Do not use DB `enum()` columns.

Eager-load relationships with per-query `->with()`. Do not set model-level `$with`.

For tenant-scoped models, extend `TenantOwnedModel` (or use `BelongsToTenant` and related Tenancy concerns). Cross-tenant lookups use `queryWithoutTenantScope()`.

Use `booted()` for model lifecycle hooks when needed. Do not add Observer classes.

## Enums

Define domain enums as string-backed cases in `app/{Domain}/Enums/`. Use TitleCase case names. Permission enums implement `TenantPermission`.

## Persistence

Keep migrations and factories under `database/` — not inside `app/{Domain}/`.

In migrations, prefer `$table->foreignId('…')->constrained()` with `cascadeOnDelete` / `nullOnDelete` as needed. For tenant-owned children that unique on `(tenant_id, parent_id)`, add a composite foreign key. Do not use `foreignIdFor`.

In Actions, persist with explicit `create` / `update` / `save`. Do not use `updateOrCreate`, `firstOrCreate`, in application code (seeders may).

Wrap multi-step writes in `DB::transaction()` when appropriate.

## Events and listeners

Prefer calling Actions directly. Emit domain events only for cross-domain side effects (for example, ClientPortal reacting to Dossier lifecycle). Place listeners under `app/{Domain}/Listeners/` — they are auto-discovered.

## Dates

App dates are `CarbonImmutable` (`Date::use` in `AppServiceProvider`). Construct with `now()` and `today()`, not `Carbon::`. Type and cast as `CarbonImmutable`.

## Rate limiting

Use named `RateLimiter::for()` limiters for Fortify authentication flows (login, 2FA, passkeys). Use inline `throttle:N,1` on feature and portal routes.

## Deliberate absences

Do not introduce these unless there is a strong, domain-specific reason:


| Absence                         | Use instead                                          |
| ------------------------------- | ---------------------------------------------------- |
| Repository layer                | Query Eloquent from Actions and Queries              |
| `app/Rules` custom rule classes | Validation rules in Form Requests                    |
| Eloquent API Resources          | Spatie Data + `Inertia::render` / `response()->json` |
| Observer classes                | `booted()` on the model                              |
| Top-level `app/Http`            | Per-domain `Http/` folders                           |




## Related

- [Architecture](architecture.md) — domains, HTTP audiences, layering, request flow
- [Adding a domain](adding-a-domain.md) — checklist for new feature areas
- [Permissions](permissions.md) — tenant permission enums and policies
- [Frontend](frontend.md) — how backend Data objects reach the UI
- [Testing](testing.md) — Pest helpers and assertion style

