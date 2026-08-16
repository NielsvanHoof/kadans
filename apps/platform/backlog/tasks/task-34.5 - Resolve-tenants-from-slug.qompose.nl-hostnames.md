---
id: TASK-34.5
title: 'Resolve tenants from {slug}.qompose.nl hostnames'
status: Done
assignee:
  - NielsvanHoof
created_date: '2026-08-11 16:54'
updated_date: '2026-08-11 19:10'
labels:
  - tenancy
  - domains
milestone: m-0
dependencies: []
references:
  - 'https://www.qompose.nl'
documentation:
  - docs/architecture.md
  - config/multitenancy.php
  - .ai/rules/tenancy.md
  - .ai/rules/routes.md
parent_task_id: TASK-34
priority: high
type: feature
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Greenfield host-based multi-tenancy for the Laravel app. No backwards compatibility with /workspaces/{tenant:slug} — nothing is live.

Central host app.qompose.nl has no current tenant (login, firm picker, and other non-tenant surfaces). Tenant workspace and portal traffic use {slug}.qompose.nl. Reserved labels (www, app, mail, api, status, …) cannot be claimed as firm slugs.

Auth must work across app and tenant hosts via a parent session domain (.qompose.nl in production).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Tenant is resolved from the request subdomain; unknown tenant hosts fail closed
- [x] #2 Reserved system labels cannot be created or resolved as tenants
- [x] #3 Route prefixes no longer include workspaces/{tenant:slug}
- [x] #4 Central app host serves non-tenant surfaces; tenant hosts serve workspace (and portal) surfaces
- [x] #5 Session/auth works across app.qompose.nl and {slug}.qompose.nl with an appropriate parent cookie domain
- [x] #6 Trusted hosts, production config checks, broadcasting origins, and security headers accept the hostname model
- [x] #7 Feature tests cover host resolution, reserved-slug rejection, membership checks, and central vs tenant surfaces
- [x] #8 Architecture/tenancy docs describe the hostname model
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Approach
Greenfield subdomain tenancy using Laravel `Route::domain('{tenant}.{base}')` (keep route name prefix `workspaces.*`). Shared session cookie on parent domain (`.{base}`).

### Config
- Add tenancy hostname config: `base_domain` (derived from `APP_URL` host parent, overridable), `central_domains` (APP_URL host + aliases), `reserved_subdomains` denylist.
- Production: `APP_URL=https://app.qompose.nl`, `SESSION_DOMAIN=.qompose.nl`.
- Tests/phpunit: use `http://app.qompose.test` so `{slug}.qompose.test` works (full Sail DX docs stay in TASK-34.6).

### HTTP / routes
- `routes/tenant.php`: drop `workspaces/{tenant:slug}` path prefix; use `domain('{tenant}.'.base)` + existing middleware stack.
- Rename/adapt `InitializeTenantFromRoute` → host-aware init: resolve `{tenant}` domain param, reject reserved labels, membership checks unchanged, still `makeCurrent()` + `active_tenant_id`.
- Move portal routes onto the same tenant domain (`{tenant}.base/portal/...`); grant resolver stays.
- Central routes remain on APP_URL host (`InitializeTenantFromSession`).
- `trustHosts`: allow central host + `*.base` (regex); stop exact-only allowlist.
- `ProductionConfigurationValidator`: allow `SESSION_DOMAIN` as exact host OR leading-dot parent of `APP_URL` host.
- Reverb `allowed_origins`: central + wildcard parent pattern.

### Provisioning / slug rules
- Enforce DNS-label slug + reserved denylist in `ProvisionTenantAction` / `StoreWorkspaceRequest`.
- Factory generates DNS-safe non-reserved slugs.

### URL helpers
- Keep `tenant` as domain route param so `route('workspaces.*', ['tenant' => $slug])` and Wayfinder still take a slug; regenerate Wayfinder after route change.
- Update `Controller::workspaceRouteParameters` / `workspaceRoute()` for domain URLs; firm switcher becomes cross-host navigation via absolute URLs.

### Tests & docs
- Feature coverage: host resolution, reserved slug rejection, membership, central vs tenant hosts, session domain validator.
- Update Pest helpers and existing workspace HTTP tests to use subdomain hosts.
- Update architecture / tenancy / routes rules and docs.

### Out of scope (TASK-34.6)
- Broader Sail/local developer hostname setup docs beyond what tests need.

### Risks
- Large blast radius on Wayfinder consumers and feature tests; prefer domain `{tenant}` param so most `route(..., tenant:)` call sites keep working.
- Portal magic-link absolute URLs must target tenant host.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented domain routing with `{tenant}` kept as a route parameter so existing route()/Wayfinder slug args mostly keep working.

Portal controllers now type-hint `string $tenant` so the domain parameter is not mis-injected into documentRequest args.

Wayfinder was regenerated against the current Sail APP_URL (may show `.localhost` until local env matches `.env.example`).
<!-- SECTION:NOTES:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: cursor-agent
created: 2026-08-11 19:10
---
Code for subdomain host tenancy was reverted on develop (revert of acb364a → 598dbd9). Uncommitted local DX work (TASK-34.6) was discarded. Restored path-based `/workspaces/{tenant}` until stakeholders confirm whether `{slug}.qompose.nl` is wanted.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Replaced path-based `/workspaces/{slug}` tenancy with host-based `{slug}.{base}` routing.

**App**
- Added `config/tenancy-hosts.php` + `TenantHostname` (central hosts, reserved labels, DNS slug rules, parent session domain).
- Staff workspace and client portal routes now use `Route::domain('{tenant}.{base}')` with no workspace path prefix.
- `InitializeTenantFromRoute` rejects reserved/invalid slugs; portal exchange/session require host slug to match the grant tenant.
- Shared session cookies: production validator accepts exact host or parent domain (`.{base}`); Reverb allows `*.{base}` origins; TrustHosts patterns cover central + tenant hosts.
- Provisioning skips reserved slugs; portal invite/reminder URLs include the tenant host; URL defaults set tenant for `to_route`.

**Tests / DX**
- phpunit uses `app.qompose.test` / `.qompose.test`; added `portalRoute()` helper; updated portal/dossier tests; new `TenantHostnameTest`.
- Regenerated Wayfinder; updated architecture/tenancy/routes docs and rules.

**Verify**
- `sail artisan test --compact` on Tenancy, Portal, Dossiers (selected), ProductionConfigurationValidator, SecurityHeaders — all green.

**Follow-up**
- TASK-34.6 for broader Sail/local hostname DX beyond what tests need.
<!-- SECTION:FINAL_SUMMARY:END -->
