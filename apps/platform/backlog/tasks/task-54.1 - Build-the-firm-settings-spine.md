---
id: TASK-54.1
title: Build the firm settings spine
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-04 20:37'
updated_date: '2026-09-04 20:57'
labels: []
dependencies: []
parent_task_id: TASK-54
priority: high
type: feature
ordinal: 113000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The four policy subtasks under TASK-54 all need the same foundation, so it lands first and they build on it.

Three existing weaknesses have to be fixed here rather than worked around, because the new consumers make them load-bearing:

ResolveTenantSettingsQuery is uncached while FirmSettingsProps already runs it on every authenticated Inertia response, and this feature adds a read from auth middleware on every workspace request plus one from the portal on every portal request.

Its read path calls firstOrCreate — a write on a GET — and abort_unless(..., 404). Both are tolerable while only the settings page calls it; both are wrong once it is called from middleware and from console loops that iterate every tenant, where a 404 becomes an HttpException that kills the sweep.

WorkspaceSettingsController::index resolves the settings twice per page load.

The clamp that enforces TASK-54s "stricter only" rule must be stated once and consumed twice: at write time by the Form Request, for a good error message, and at read time as defence in depth, so a row edited directly in SQL cannot weaken security. The existing reminder_interval_days bounds are duplicated across StoreDossierRequest and UpdateDossierRequest — that is the mistake not to repeat.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 tenant_settings gains nullable columns for 2FA enforcement and grace days, portal idle and absolute minutes, the default reminder interval, and the two retention windows
- [x] #2 Reading settings performs no INSERT and never aborts, in HTTP, queue and console contexts alike
- [x] #3 Repeated reads within one request hit the database once
- [x] #4 Saving a setting makes the next read return the new value immediately
- [x] #5 A value weaker than the platform config is clamped on read, and the write-time rules and the read-time clamp agree for every setting
- [x] #6 The shared firm_settings prop carries only what non-admin UI needs, not the security, portal or retention sections
- [x] #7 The settings page renders one section per policy area using the projects Inertia Form pattern
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Migration: add nullable policy columns to tenant_settings (two_factor_enforcement string; two_factor_grace_days, portal_session_idle_minutes, portal_session_absolute_minutes, default_reminder_interval_days, retention_archived_days, retention_activity_log_days as unsignedSmallInteger). Nullable means inherit, so no backfill.
2. App\Tenancy\Settings: IntegerBound + TwoFactorEnforcementBound + FirmSettingBounds registry. One declaration per setting, emitting BOTH the Form Request rules and the read-time clamp from the same private ceiling calculation, so the two halves cannot drift. Lives in Tenancy because ClientPortal (L5) may import it and Tenancy (L2) may not import ClientPortal.
3. TwoFactorEnforcement gains severity()/isAtLeastAsStrictAs()/strictest(). Docblock records that the ordering is only total while security.two_factor.permission stays platform-level.
4. TenantSettingsCache: Cache::memo()->remember like TenantLookup (not Cache::flexible - it serves stale by design, wrong for a security-relevant value), tenant id in the key, SCHEMA_VERSION bumped in the same commit as the migration. Caches the raw attribute array, never the resolved Data object, which would bake config() into Redis for the TTL.
5. Invalidate from a TenantSetting::saved/deleted model event in TenancyServiceProvider - one site. Note in the docblock that cascadeOnDelete fires no Eloquent events.
6. ResolveTenantSettingsQuery: split forTenantOrNull() (read-only, cached, never aborts) from forTenant() (write path, keeps firstOrCreate, settings page and provisioning only). handle() falls back to platform defaults on a missing row. Removes the write-on-GET and the abort_unless from every new caller.
7. TenantSettingsData::fromModel(?TenantSetting) applies the clamp at the single construction site every consumer passes through. Nested section Data objects carry effective value + inherited flag + platform default.
8. Trim FirmSettingsProps to the AI flags plus the reminder default; security/portal/retention ship only in WorkspaceSettingsPageData.
9. Collapse the double resolve in WorkspaceSettingsController::index.
10. Rebuild the settings page as one Card per section on the house Inertia <Form> pattern; lang:organize; wayfinder:generate; pint.
11. Tests: bounds unit test asserting rules() and resolve() agree across a value grid; cache test asserting one query per request and immediate freshness after save; read-only test asserting no INSERT and no abort without a tenant.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Landed the spine.

Bounds are the load-bearing idea: App\Tenancy\Settings\{IntegerBound,TwoFactorEnforcementBound,FirmSettingBounds} declare each setting once and emit BOTH the Form Request rules and the read-time clamp from the same numbers. FirmSettingBoundsTest walks a value grid per setting asserting "passes validation" iff "resolve() returns it unchanged", so the two halves cannot drift apart silently.

Three deviations from the plan, all deliberate:

1. Cache uses Cache::memo()->remember (TenantLookup pattern) rather than StaffOptionsCache Cache::flexible. flexible() serves stale by design, which is wrong for a row that will decide 2FA enforcement: a firm that tightens a setting would keep being served the loose one. memo also gives the in-process dedupe, which is the actual win when middleware reads this on every deferred-prop request.

2. Cache stores the raw attribute array, never TenantSettingsData. The resolved object substitutes platform config for every null, so caching it would bake config() into the cache for the TTL and a deploy that tightened the platform floor would be ignored until the key expired.

3. Invalidation is a TenantSetting::saved/deleted model event, not explicit forget() calls in the two actions. The reason StaffOptionsCache avoids model events (last_accessed_at thrash) has no equivalent here. One site cannot be forgotten by whoever adds the next setting.

ResolveTenantSettingsQuery is now split: forTenantOrNull()/forTenantIdOrNull() are read-only, cached and never abort; forTenant() keeps firstOrCreate + abort_unless for the two callers that legitimately want them (the Form Request resolving a model for the policy, and the update action). This removes a write-on-GET and an HttpException from every path that TASK-54.4/54.5/54.6 will add.

Two things future subtasks depend on: portalSessionIdleMinutes/AbsoluteMinutes are keyed by tenant id, not Tenant::current(), because portal.exchange runs outside the resolver middleware. And TwoFactorEnforcement gained severity()/strictest(); TASK-54.6 must apply strictest() in the middleware itself rather than trusting the resolver, so the production floor holds by construction.

Copy note: replaced the old AI-section string that named AWS Bedrock, per the no-stack-leakage rule.

Validation: pest tests/Feature/Tenancy tests/Feature/Platform tests/Feature/Auth tests/Feature/Security tests/Feature/Settings tests/Feature/Localization tests/Architecture tests/Unit => 733 passed. pest tests/Feature/ClientPortal tests/Feature/Dossiers tests/Feature/DocumentProcessing tests/Feature/Questionnaires tests/Feature/Retention tests/Feature/Portal => 462 passed. npm run test => 655 passed (121 files). tsc --noEmit clean. lang:organize --check up to date, en/nl parity holds. pint and vp check clean.

Had to bump the pinned dictionary size in TranslationDomainsTest and TranslationLoadingTest from 1416 to 1449 (+36 new keys, -3 replaced AI strings).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Extended tenant_settings from three AI booleans to a real per-firm policy store, and rebuilt the read path so the settings can be consulted from anywhere.

Added seven nullable columns (null = inherit the platform value), a bounds layer that states each setting once and drives both validation and a read-time clamp, a Cache::memo-backed settings cache invalidated from a model event, and a firm settings page of five sections on the house Inertia Form pattern. Split ResolveTenantSettingsQuery into a read side that never writes or aborts and a write side that still does both, and trimmed the every-request firm_settings shared prop down to the AI flags plus the reminder default so a firm security and retention policy no longer ships to every staff member on every page.

Verified with 733 passing backend tests across Tenancy, Platform, Auth, Security, Settings, Localization, Architecture and Unit, plus 462 in the consumer domains and 655 frontend tests. Behaviour proved rather than asserted from code: a weaker value is rejected on save and clamped when read back through the page props, an empty field persists as inherited rather than zero, repeated reads in one request issue one query, saving is immediately visible, and reading performs no INSERT and does not abort without a tenant.
<!-- SECTION:FINAL_SUMMARY:END -->
