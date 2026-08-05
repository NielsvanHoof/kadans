# Qompose Platform

Multi-tenant platform for accounting and advisory firms to collect documents and answers from their clients. Firms build questionnaires per dossier (file uploads, text answers, yes/no confirmations), clients respond through a magic-link portal, and staff review, approve, or request corrections until the dossier is complete.

## Stack

- **Backend:** Laravel 13 (PHP 8.5), Octane + FrankenPHP, Fortify (auth, 2FA, passkeys), Spatie Multitenancy + Permission, activity log
- **Frontend:** Inertia v3 + React 19, Tailwind CSS 4, shadcn/ui, Wayfinder (typed routes)
- **Tooling:** Laravel Sail, Pest 4, Pint, Larastan, Biome + Prettier, Vite 8

## Documentation

Longer guides live under [`docs/`](docs/):

- [Getting started](docs/getting-started.md) — Sail boot, first owner, day-to-day commands
- [Architecture](docs/architecture.md) — domains, composition root, request flow
- [Permissions](docs/permissions.md) — enum → registry → Spatie → policies
- [Security](docs/security.md) — threat model, tenant isolation, portal tokens, response hardening
- [Adding a domain](docs/adding-a-domain.md) — checklist for a new feature area

[`CONTEXT.md`](CONTEXT.md) is the short domain glossary (entities and workflow terms).

## Getting started

Everything runs through Sail. Prefer the [full getting-started guide](docs/getting-started.md) on a fresh clone (migrate inside Sail after containers are up). Quick path once Docker is healthy:

```bash
composer run setup          # install deps, .env, key, migrate, build assets
vendor/bin/sail up -d       # start services and the Octane application server
vendor/bin/sail composer run dev   # queue + logs + Reverb + Vite
```

Create a first firm owner:

```bash
vendor/bin/sail artisan tenancy:create-owner
```

Useful commands:

```bash
vendor/bin/sail artisan test --compact   # backend tests (Pest)
vendor/bin/sail npm run types:check      # TypeScript
vendor/bin/sail npm run lint:check       # Biome (lint + import boundaries)
vendor/bin/sail npm run format:check     # Prettier
vendor/bin/sail bin pint --dirty         # PHP formatting
```

Octane reloads its workers automatically when backend files change. After changing
`compose.yaml`, rebuild the local image with `vendor/bin/sail build --no-cache`.

## Domain overview

- **Tenant / workspace** — a firm; all staff data is scoped per tenant.
- **Dossier** — a case for one client (`draft → awaiting_client → in_review → completed`).
- **Document request** — one questionnaire item on a dossier. Typed: `file`, `text`, or `boolean`. Status: `pending → submitted → accepted | rejected`.
- **Questionnaire template** — reusable set of items (system-wide or firm-owned) that can be applied to a dossier.
- **Client portal** — token-based magic-link page (no account) where clients answer requests.

Backend code is organized by domain under `app/` (Dossiers, Clients, Tenancy, …). Domain bindings and policies live in each domain’s `*ServiceProvider`; `AppServiceProvider` keeps cross-cutting defaults. See [architecture](docs/architecture.md) and [backend conventions](docs/backend.md).

## Frontend architecture

`resources/js` is organized by feature/domain: thin `pages/` compose `features/<domain>/`; shared UI stays in `components/`; Wayfinder output lives in gitignored `actions/` and `routes/`.

Run `vendor/bin/sail npm run dev` (or `npm run build`) so Wayfinder generates those folders — required for IDE type resolution and `npm run types:check`.

Key conventions (enforced by Biome; see [Frontend](docs/frontend.md) and [`biome.json`](biome.json)):

- Payload types are generated from the PHP `*Data` objects into `types/generated/` — `features/<domain>/types.ts` mostly aliases them.
- Shared `components/` never import from `features/`, and are grouped by role (`ui/`, `primitives/`, `data/`, `forms/`, `feedback/`, `brand/`, `app-shell/`).
- `hooks/` and `lib/` are app-wide only; domain hooks live in `features/<domain>/hooks/`.
- Staff and portal document-request renderers never import each other; the item-type registries wire both sides.

## Testing

Feature tests live in `tests/Feature` (Pest). See [testing conventions](docs/testing.md). Run the ones near your change:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Dossiers/
vendor/bin/sail artisan test --compact --filter='portal'
```
