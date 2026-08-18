---
id: TASK-34.6
title: Align local development with app and tenant hostnames
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-11 16:54'
updated_date: '2026-08-11 19:10'
labels:
  - tenancy
  - dx
  - domains
milestone: m-0
dependencies:
  - TASK-34.5
references:
  - .env.example
  - phpunit.xml
  - vite.config.ts
documentation:
  - .env.example
  - docs/architecture.md
  - docs/getting-started.md
  - scripts/ensure-local-hosts.sh
modified_files:
  - .env.example
  - composer.json
  - vite.config.ts
  - scripts/ensure-local-hosts.sh
  - app/Tenancy/Support/TenantHostname.php
  - app/Tenancy/Console/Commands/ShowLocalUrlsCommand.php
  - app/Tenancy/Console/Commands/CreateOwnerCommand.php
  - docs/getting-started.md
  - docs/architecture.md
  - README.md
  - tests/Feature/Tenancy/TenantHostnameTest.php
  - tests/Feature/Tenancy/CreateOwnerCommandTest.php
  - tests/Feature/Tenancy/ShowLocalUrlsCommandTest.php
  - .ai/rules/docs.md
  - .ai/rules/general.md
parent_task_id: TASK-34
priority: medium
type: chore
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Developers and CI need a local equivalent of app.qompose.nl and {slug}.qompose.nl so host-based tenancy can be exercised without production DNS.

Outcome: Sail/local docs and env examples use a consistent base domain (for example qompose.test or localhost conventions) for central and tenant hosts, and tests can target those hosts.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Local env example documents central and tenant hostnames that match the production model
- [x] #2 Developers can open central and tenant hosts against Sail without manual guesswork
- [x] #3 Automated tests can request tenant and central hosts reliably
- [x] #4 DX/docs explain the local hostname setup
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Approach
Keep the local hostname model aligned with production: `app.qompose.test` (central) and `{slug}.qompose.test` (tenant), matching `phpunit.xml` / `.env.example` from TASK-34.5. Close the DX gap so Sail browsers and docs do not still point at bare `localhost`.

## Steps
1. **Env DX** — Expand `.env.example` comments for APP_URL / TENANCY_BASE_DOMAIN / SESSION_DOMAIN; set `SESSION_SECURE_COOKIE=false` for HTTP Sail (production validator still requires secure).
2. **Hosts script** — Add `scripts/ensure-local-hosts.sh` + `composer hosts:local` to idempotently map `app.{base}` (and optional `--tenant=` slugs) to `127.0.0.1` on the host `/etc/hosts` (must run on host, not inside Sail).
3. **Artisan URLs** — Add `TenantHostname` origin/URL helpers; `tenancy:local-urls` prints central + tenant URLs and hosts-script reminder; `tenancy:create-owner` prints login + workspace URLs after provision.
4. **Vite** — Pin Sail-friendly `server.hmr.host` (and explicit `*.qompose.test` CORS if needed; plugin already allows `*.test`).
5. **Docs** — Update `docs/getting-started.md` Useful URLs + hostname section; add short host model note in `docs/architecture.md`; point README at the hosts step.
6. **Tests** — Cover URL helpers, create-owner URL output, and that workspace/portal routes resolve on `*.qompose.test`.

## Out of scope
- Changing base domain to `*.localhost`
- Production DNS / AWS (TASK-34.1+)

## Validation
- `vendor/bin/sail artisan test --compact` on Tenancy hostname + CreateOwner tests
- Pint on dirty PHP
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verified AC#1–#4: env comments + SESSION_SECURE=false; hosts:local dry-run maps app/tenant; Pest route helpers + phpunit APP_URL; getting-started/architecture/README hostname sections.

Laravel Prompts table output is not asserted by Pest — URL lines use `$this->table` / `$this->line` instead.
<!-- SECTION:NOTES:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: cursor-agent
created: 2026-08-11 19:10
---
Local hostname DX was never committed; working-tree changes discarded when reverting host-based tenancy. Parked until product confirms subdomain tenancy is needed.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Aligned local/Sail DX with host-based tenancy from TASK-34.5 so developers and tests use the same model as production: `app.qompose.test` (central) and `{slug}.qompose.test` (tenant).

### Changes
- **`.env.example`** — Documented central/tenant host env vars; set `SESSION_SECURE_COOKIE=false` for HTTP Sail (production validator still requires secure).
- **`scripts/ensure-local-hosts.sh` + `composer hosts:local`** — Idempotent `/etc/hosts` block for `app.{base}` and optional `--tenant=` slugs (run on the host).
- **`tenancy:local-urls`** — Prints configured URLs; **`tenancy:create-owner`** prints central/workspace URLs after provision.
- **`TenantHostname::centralUrl()` / `urlForTenant()`** — Shared origin helpers.
- **Vite** — `server.hmr.host = localhost` for Sail while browsing `*.qompose.test`.
- **Docs** — `docs/getting-started.md`, `docs/architecture.md`, `README.md` updated off bare `localhost`.

### Tests
`vendor/bin/sail artisan test --compact` on:
- `tests/Feature/Tenancy/TenantHostnameTest.php`
- `tests/Feature/Tenancy/CreateOwnerCommandTest.php`
- `tests/Feature/Tenancy/ShowLocalUrlsCommandTest.php`

All passed (13 tests).

### Verify locally
```bash
composer hosts:local -- --tenant=acme
vendor/bin/sail artisan tenancy:local-urls --tenant=acme
# open http://app.qompose.test and http://acme.qompose.test
```
<!-- SECTION:FINAL_SUMMARY:END -->
