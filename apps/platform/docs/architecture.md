# Architecture

This application is a multi-tenant Laravel app with **folder-based domains** under `app/`. Cross-cutting defaults live in `AppServiceProvider`; domain bindings and policies live in each domain’s `*ServiceProvider`.

## Domains

| Domain | Responsibility |
|--------|----------------|
| **Accounts** | Users, Fortify hooks, profile, locale, security |
| **Audit** | Activity log model, audit permissions, tenant audit helpers |
| **ClientPortal** | Magic-link grants, portal middleware, guest session |
| **Clients** | Firm CRM clients |
| **DocumentProcessing** | OCR drivers, pipelines, related jobs/commands |
| **Dossiers** | Cases, document requests, uploads, exports, reminders |
| **Platform** | Support sessions, Inertia shared props, retention, readiness |
| **Questionnaires** | Reusable questionnaire templates |
| **Reporting** | Dashboards / activity / media UI over other domains’ models |
| **Shared** | Base controller, pagination helpers, shared DTOs, cross-layer contracts |
| **Tenancy** | Tenants, memberships, invitations, permission infrastructure |
| **WorkspaceNotifications** | In-app notification inbox |
| **Providers** | App-level providers (`AppServiceProvider`, Fortify, Health) plus per-domain `*ServiceProvider` classes |

`app/Providers/` holds framework/app providers. Domain `*ServiceProvider` classes live next to their domain code under `app/{Domain}/`.

### Typical domain layout

A full feature domain (e.g. Dossiers) looks like:

```
app/{Domain}/
├── Actions/           # writes — one class per use case (*Action::handle)
├── Console/Commands/  # optional — register path in bootstrap/app.php
├── Data/              # readonly DTOs for Inertia props
├── Enums/             # status enums + *Permission implementing TenantPermission
├── Http/
│   ├── Controllers/
│   └── Requests/
├── Jobs/              # optional
├── Models/
├── Policies/          # AuthorizesTenantPermission
├── Queries/           # reads — Fetch*Query, existence helpers
├── Transitions/       # optional state machines
└── {Domain}ServiceProvider.php  # policies + container bindings
```

Thin domains (e.g. Reporting, WorkspaceNotifications) may only have `Http/`, `Queries/`, `Data/`, and sometimes `Actions/` — no models or policies when they reuse another domain’s models.

## Composition root

Domains are PHP namespaces with their own service providers for bindings and policies. The app registers them explicitly:

| Concern | Where |
|---------|-------|
| Routes | [`bootstrap/app.php`](../bootstrap/app.php) loads `routes/web.php`, then `health`, `tenant`, `portal` |
| Console commands | `withCommands([...])` paths under domain `Console/Commands` |
| Event listeners | Discovery: `app/*/Listeners` |
| Policies | `Gate::policy()` in the owning domain’s `*ServiceProvider` |
| Container bindings | Domain `*ServiceProvider::register()` (e.g. OCR/AWS in `DocumentProcessingServiceProvider`) |
| Morph map | `Relation::enforceMorphMap()` in [`AppServiceProvider`](../app/Providers/AppServiceProvider.php) |
| Permissions | Enums listed in [`config/tenant-permissions.php`](../config/tenant-permissions.php) |

Providers registered in [`bootstrap/providers.php`](../bootstrap/providers.php):

- App-level: `AppServiceProvider`, `FortifyServiceProvider`, `HealthServiceProvider`
- Domain: `Platform`, `Tenancy`, `DocumentProcessing`, `Dossiers`, `Audit`, `Clients`, `ClientPortal`, `Questionnaires`, `Reporting`

`AppServiceProvider` stays thin — defaults, factory discovery, morph map, and local tooling. Domain wiring (AWS/OCR, gates, policies) belongs in the matching domain provider.

## HTTP audiences

```
routes/
├── web.php              # auth, session tenant; requires web/*
├── web/                 # reporting, tenancy/firms, platform, invitations
├── tenant.php           # workspace staff API/UI under workspaces/{tenant:slug}
├── tenant/              # clients, dossiers, members, questionnaires, …
├── portal.php           # guest client portal
├── settings.php
├── health.php
├── console.php
└── channels.php
```

**Tenant workspace stack** ([`routes/tenant.php`](../routes/tenant.php)):

1. `web`, `auth`, `verified`
2. `InitializeTenantFromRoute` — set current tenant from `{tenant:slug}` before model binding
3. `EnsureValidTenantMembership`
4. `SetPermissionTeamContext` — Spatie permission “team” = tenant
5. `NeedsTenant`

Domain route files only declare relative paths; the prefix and name (`workspaces.`) are applied once.

**Portal** uses its own middleware (`ResolveClientPortalGrant`, `HardenClientPortalResponse`) and does not use staff session tenancy the same way.

## Request flow

```mermaid
flowchart LR
  Request --> Routes
  Routes --> Middleware
  Middleware --> Controller
  Controller --> Policy
  Controller --> ActionOrQuery
  ActionOrQuery --> Model
  Controller --> InertiaPage
  InertiaPage --> FeatureUI
```

Controllers stay thin: authorize → call an Action or Query → return `Inertia::render()` (or a redirect/download).

### Backend patterns

- **Actions** — mutations and multi-step writes (`CreateDossierAction`, …)
- **Queries** — read models / index / show payloads (`FetchDossierIndexQuery`, …)
- **Data** — readonly DTOs shaped for the frontend
- **Transitions** — status rules (`DossierTransitions`, `DocumentRequestTransitions`)
- **BelongsToTenant** — tenant-scoped Eloquent models ([`app/Tenancy/Concerns/BelongsToTenant.php`](../app/Tenancy/Concerns/BelongsToTenant.php)). Soft by default (no current tenant → empty results); workspace/portal opt into strict throw via `TenantContext`. Cross-tenant lookups use `queryWithoutTenantScope()`, not `withoutGlobalScopes()`.
- **TenantContext** — `runForTenant()` for console/async code; opt-in `enableStrict()` / `runStrict()` for fail-fast scoping (Octane-safe via Laravel Context)

### Domain layering

Domains are ordered; a domain may use domains below it and its own layer
siblings, never above. Enforced in [`tests/Pest.php`](../tests/Pest.php).

```
L0  Shared
L1  Accounts
L2  Tenancy
L3  Questionnaires
L4  Clients, Dossiers, DocumentProcessing
L5  ClientPortal
L6  Reporting, Platform, WorkspaceNotifications
```

**Audit is cross-cutting**, not a layer: any domain may log activity, and Audit
itself may only reach Accounts and Tenancy.

Three pairs deliberately share a layer because the dependency really is mutual —
`Clients`/`Dossiers` (a dossier is a case *for* a client, and deleting a client
cascades) and `Dossiers`/`DocumentProcessing` (OCR and export pipelines are part
of the upload and dossier lifecycle).

When a lower layer needs something from a higher one, invert it with a contract
the lower layer owns and the higher one binds — see
`Tenancy\Contracts\SupportSessionState`, `Dossiers\Contracts\ClientPortalReminders`,
and `Shared\Cache\TenantCacheInvalidator`.

Migrations and factories stay under `database/` — not inside `app/{Domain}`.

## Frontend

`resources/js` mirrors domains:

| Area | Role |
|------|------|
| `pages/` | Thin Inertia entry points — compose features |
| `features/<domain>/` | Domain UI, domain hooks (`hooks/`), co-located `types.ts` |
| `components/` | Shared only, grouped by role — never imports from `features/` |
| `hooks/`, `lib/` | App-wide hooks and generic helpers only — nothing domain-aware |
| `layouts/` | One folder per audience (`app/`, `auth/`, `onboarding/`, `portal/`, `settings/`, `dossiers/`) |
| `types/` | Cross-cutting types (`auth`, `navigation`, `ui`, `pagination`) |
| `types/generated/` | Generated from PHP Data objects + enums — do not edit |
| `actions/`, `routes/` | Wayfinder-generated — do not edit |

`components/` sub-groups, bottom layer first:

| Group | Contents |
|-------|----------|
| `ui/` | shadcn primitives (vendored, lint-ignored) |
| `primitives/` | single-purpose, no composition (`heading`, `input-error`, `empty-state`, …) |
| `data/` | `data-table`, `index-list`, `index-query/`, `sortable/` |
| `forms/` | `file-dropzone`, `date-picker` |
| `feedback/` | `confirm-destroy-dialog` |
| `brand/` | brand panel, `ledger-field`, `audit-mark` |
| `app-shell/` | nav, sidebar, breadcrumbs |

**Payload types are generated.** Domain payload shapes come from the PHP `*Data`
objects (`php artisan typescript:transform`, part of `npm run types:check`), so
`features/<domain>/types.ts` is mostly aliases like
`export type Dossier = App.Dossiers.Data.DossierShowData`. Add a field in the PHP
DTO, not in TypeScript; CI fails if the committed generated output is stale.

Rough backend → frontend map:

| Backend | Pages / features |
|---------|------------------|
| Dossiers | `pages/dossiers`, `features/dossiers`, `features/document-requests` |
| Clients | `pages/clients`, `features/clients` |
| Questionnaires | `pages/questionnaires`, `features/questionnaires` |
| ClientPortal | `pages/portal`, `features/portal` (shell) + `features/document-requests/portal` (renderers) |
| Tenancy | firms, onboarding, members, invitations |
| Reporting | dashboard / activity / media under workspaces |
| DocumentProcessing | pipelines under workspaces |
| Accounts | settings, auth, security |

Import boundaries and nesting rules are enforced by Biome — see [`.cursor/rules/frontend-architecture.mdc`](../.cursor/rules/frontend-architecture.mdc).

Questionnaire item types are defined once in PHP (`QuestionnaireItemType`), codegen’d to `features/document-requests/item-types/generated.ts`, and wired in staff (`questionnaire-item-type-registry.tsx`) and portal (`item-types/portal-registry.ts`) registries.

## Tests

Feature tests live under `tests/Feature/{Domain}/` (and related folders such as `Portal`). Prefer factories from `database/factories/`. Run the suite near your change with Sail + Pest.
