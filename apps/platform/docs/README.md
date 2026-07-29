# Platform documentation

Guides for how this application is structured and how to extend it.

## Mental model

| Layer | Role |
|-------|------|
| `app/{Domain}/` | Code ownership (PSR-4 under `App\`) |
| `routes/{web,tenant,portal}/` | HTTP surface, grouped by audience |
| `bootstrap/` + `App\Providers\AppServiceProvider` | Composition root — wiring, not domain code |
| `database/` | Shared persistence (migrations, factories, seeders) |
| `resources/js/{pages,features}` | UI ownership parallel to domains |

Domains are **folder namespaces**, not Laravel packages. There are no per-domain service providers. Routes, policies, morph map entries, and permission enums are registered explicitly at the application root.

## Reading order

1. [Getting started](getting-started.md) — local Sail setup and first login
2. [Architecture](architecture.md) — domains, request flow, backend/frontend patterns
3. [Permissions](permissions.md) — how tenant permissions are defined and enforced
4. [Adding a domain](adding-a-domain.md) — checklist for a new feature area

## Related

- [README](../README.md) — stack overview and quick commands
- [CONTEXT](../CONTEXT.md) — domain glossary (entities and workflow terms)
- [Frontend architecture rule](../.cursor/rules/frontend-architecture.mdc) — `resources/js` conventions
- [Infra README](../infra/README.md) — optional AWS / OCR deployment notes
