# Platform documentation

Guides for how this application is structured and how to extend it.

## Mental model

| Layer | Role |
|-------|------|
| `app/{Domain}/` | Code ownership (PSR-4 under `App\`) |
| `routes/{web,tenant,portal}/` | HTTP surface, grouped by audience |
| `bootstrap/` + `App\Providers\AppServiceProvider` | App composition root — defaults, morph map, local tooling |
| `app/{Domain}/*ServiceProvider` | Domain bindings and policies |
| `database/` | Shared persistence (migrations, factories, seeders) |
| `resources/js/{pages,features}` | UI ownership parallel to domains |

Domains are **folder namespaces**, not Laravel packages. Each domain that needs policies or container bindings has a `*ServiceProvider` registered in `bootstrap/providers.php`. The morph map and app-wide defaults stay in `AppServiceProvider`.

## Reading order

1. [Getting started](getting-started.md) — local Sail setup and first login
2. [Architecture](architecture.md) — domains, request flow, layering
3. [Backend](backend.md) — PHP conventions (Actions, Queries, models, validation)
4. [Frontend](frontend.md) — Inertia React structure, Wayfinder, types, Biome rules
5. [Testing](testing.md) — Pest helpers, factories, assertions, Vitest
6. [Permissions](permissions.md) — how tenant permissions are defined and enforced
7. [Security](security.md) — threat model, tenant isolation, portal tokens, response hardening
8. [Adding a domain](adding-a-domain.md) — checklist for a new feature area

## Related

- [README](../README.md) — stack overview and quick commands
- [CONTEXT](../CONTEXT.md) — domain glossary (entities and workflow terms)
- [biome.json](../biome.json) — frontend import boundaries (see [Frontend](frontend.md))
- [Infra README](../infra/README.md) — optional AWS / OCR deployment notes
