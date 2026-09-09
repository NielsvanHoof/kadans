---
id: TASK-54.2
title: Let a firm change its name and URL
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-04 20:37'
updated_date: '2026-09-05 08:54'
labels: []
dependencies:
  - TASK-54.1
parent_task_id: TASK-54
priority: medium
type: feature
ordinal: 114000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
A firm is named once, at provisioning, and can never be corrected. A typo in the firm name is permanent, and a firm that rebrands carries the old name on every page and in every invitation email.

FirmController has create and store only; there is no update path anywhere. The slug is generated from the name by ProvisionTenantAction::createTenantWithAvailableSlug and is the workspace route key, so renaming touches the URL every staff member has bookmarked.

The user decided the slug is editable rather than frozen, accepting that old workspace URLs stop working. The copy has to say so plainly, in product language, without mentioning slugs or routing.

Worth knowing before starting: Tenant::booted already forgets the TenantLookup directory cache on updated, and TenantLookup::forget already drops the getOriginal("slug") key as well as the current one. That path was written for a rename that never shipped, so the cache side is already handled.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A firm administrator can change the firm name and its URL from the firm settings page
- [x] #2 Saving redirects to the settings page under the new URL
- [x] #3 The old URL no longer resolves, and the firm switcher shows the new name immediately
- [x] #4 A URL already taken by another firm is rejected with a clear message
- [x] #5 The copy warns that existing links to the firm will stop working
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. RenameTenantAction (app/Tenancy/Actions): update name + slug on the bound Tenant instance. No cache work needed - Tenant::booted already forgets both the current and the getOriginal("slug") TenantLookup keys on updated.
2. UpdateWorkspaceRequest: name reuses StoreWorkspaceRequest bounds (required|string|max:120). Slug is normalised through Str::slug in prepareForValidation so a firm can type its name into the field and get a usable URL, then validated required|string|max:255|alpha_dash|unique ignoring itself. Normalising before validation means uniqueness is checked against the value actually stored.
3. Route PATCH settings/firm -> workspaces.settings.firm.update, plus an updateFirm method on WorkspaceSettingsController. Redirect via workspaceRouteParameters(), which reads request()->route("tenant") - the same instance the action mutated, so it resolves to the NEW slug. Flash inertia.refresh.workspaces so the once-cached switcher list picks the new name up; current_firm is a plain closure prop and refreshes itself.
4. Frontend: a firm identity Card as its own <Form> above the policy form (different endpoint, different table, and it redirects). Show the resulting URL and warn plainly that existing links stop working.
5. Tests: rename redirects to the new slug; the old slug 404s; a taken URL is rejected and nothing is written; the switcher refresh flag is set; adviser is forbidden. Use provisionWorkspace/workspaceMember/workspaceRoute per the tests rule.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Found and fixed a real Octane bug while verifying AC #3.

The first version of that test asserted the mechanism — that inertia.refresh.workspaces was flashed — and passed. Rewritten to assert the outcome (follow the redirect, read current_firm off the next page) it failed: the firm still reported its OLD name.

Cause: spatie/laravel-multitenancy binds the current tenant with app()->instance("currentTenant", ...), and ImplementsTenant::makeCurrent() returns early when isCurrent() matches — and isCurrent() compares only the primary key. On Octane the container outlives the request, so InitializeTenantFromRoute loads a correct fresh record every request, calls makeCurrent(), and that call does nothing. The worker keeps serving the pre-rename object until it happens to serve a different firm. Not a test artifact; it would ship.

RenameTenantAction now calls Tenant::forgetCurrent() then makeCurrent() after the update. Recorded in .ai/rules/tenancy.md for the next writer of a tenant-row mutation. (Written to the file directly rather than through Boost record-rule — the laravel-boost MCP server failed to connect this session.)

Everything else went as planned. The cache side needed no work, exactly as the task description predicted: Tenant::booted forgets both the new and the getOriginal("slug") TenantLookup keys, so the old address 404s immediately.

Slug is normalised through Str::slug in prepareForValidation, before validation, so the uniqueness check runs against the value that will actually be stored and a firm can type its own name into the address field. An address that reduces to an empty string is refused rather than silently kept.

Also had to register updateFirm in CONTROLLER_NON_REST_ACTIONS — tests/Unit/ControllerActionsTest guards that every non-REST controller action is declared.

Validation: pest tests/Feature/Tenancy/FirmRenameTest.php => 11 passed. Core suites (Tenancy, Platform, Auth, Security, Settings, Localization, Architecture, Unit) => 744 passed. ClientPortal/Dossiers/Clients => 195 passed. npm run test => 658 passed (122 files). tsc clean, vp check clean, pint clean, lang:organize --check up to date with en/nl parity.

Follow-up after review: the settings page was turning into a long scroll, so the six groups are now tabs (Details, Signing in, Client portal, Reminders, Retention, Assisted features).

User chose one shared save button over per-tab saves, so the backend contract is unchanged — one PATCH still carries every field.

Two traps that came out of it, both fixed and covered:

1. React Aria unmounts a panel that is not selected, and an absent field is not an empty one — saving from any single tab would have blanked every setting on the tabs the reader had not opened. The five policy panels are shouldForceMount. Proved by a Vitest test that submits from the Signing in tab and asserts all nine field names reach FormData.

2. shouldForceMount only marks the other panels `inert`, which removes them from the accessibility tree and the tab order but leaves them ON SCREEN — every panel would have rendered stacked, which is the exact problem tabs were meant to solve. They now carry data-[inert]:hidden (declared once as INACTIVE_PANEL_CLASS). display:none still submits its inputs; only disabled would drop them. Verified two ways: a test asserting exactly one panel is non-inert and every inert one carries the class, and grepping the built CSS for the emitted rule, which is [data-inert]{display:none}.

Because one button now saves fields the reader cannot see, a failed save switches to the first tab with an error (Form onError, a top-level prop — not an options key) and the failing tabs show a dot with an sr-only "(needs attention)".

The firm details form stays OUTSIDE the policy form. A form nested in another form is invalid and the inner one never submits; Tabs shares state by context, so the panel can be a sibling and DOM order keeps the strip on top.

The tabbed body lives in features/workspaces/settings/firm-settings-tabs.tsx, not the page: an eslint rule blocks importing @/pages/** ("Pages are Inertia entry points, not libraries"), so the page stays a thin entry point and the component is testable.

Validation: npm run test => 662 passed (123 files). pest Tenancy/Localization/Unit/Architecture => 397 passed. tsc, vp check clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A firm can now correct its name and move its workspace address from the firm settings page.

Added RenameTenantAction, UpdateWorkspaceRequest (slug normalised through Str::slug before validation, so uniqueness is checked against what will be stored), a PATCH settings/firm route and controller method that redirects to the new address, and a firm details card that states plainly that saved links will stop working.

Verified by outcome rather than by mechanism, which is what made it worth doing: 11 tests covering the redirect landing on the new address, the old address returning 404, the switcher showing the new name on the very next page, a taken address being rejected with nothing written, an address that reduces to nothing being refused, and three non-privileged roles being forbidden.

That outcome-based switcher test caught a real Octane bug: makeCurrent() short-circuits on a matching primary key, so a renamed firm kept serving its previous name from the reused worker. The action now forgets and rebinds the current tenant, and the trap is recorded in .ai/rules/tenancy.md.

744 backend tests across the core suites, 195 in consumer domains, 658 frontend, with tsc, vp check, pint and lang:organize --check all clean.
<!-- SECTION:FINAL_SUMMARY:END -->
