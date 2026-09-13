---
id: TASK-54.4
title: Let a firm shorten its client portal sessions
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-04 20:37'
updated_date: '2026-09-06 11:22'
labels: []
dependencies:
  - TASK-54.1
parent_task_id: TASK-54
priority: medium
type: feature
ordinal: 116000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The portal session window is one pair of numbers for every firm on the platform: PORTAL_SESSION_IDLE_MINUTES (15) and PORTAL_SESSION_ABSOLUTE_MINUTES (240). A firm handling more sensitive client material cannot ask for a tighter window without a redeploy that would tighten it for everyone.

Under TASK-54s stricter-only rule a firm may only shorten. That matters beyond policy: ProductionConfigurationValidator refuses to boot production when the idle window is at or above session.lifetime, and because a firm can only shorten, that boot guarantee keeps covering every firm with no change to the validator.

Two traps found while planning:

PortalSessionWindow::open runs at portal.exchange, which sits outside the ResolveClientPortalGrant middleware group on purpose (routes/portal.php). Tenant::current() is null there, while extend() runs inside the group where it is not. The per-firm lookup has to key off the grants tenant_id, which both paths already have.

The bounds cannot live in App\ClientPortal: tests/Pest.php asserts App\Tenancy must not use App\ClientPortal, and the MIN/MAX constants are private. They belong in Tenancy, which ClientPortal may import freely.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A firm administrator can shorten the portal idle and absolute session windows, or leave them inherited
- [x] #2 A window longer than the platform value is rejected on save and clamped on read
- [x] #3 A shortened window applies from the moment a client opens their link, not only on later requests
- [x] #4 The absolute window can never be shorter than one idle window
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. New App\ClientPortal\Support\PortalSessionLimits: a readonly value object holding the two resolved minute counts, with forTenant(int $tenantId) going through ResolveTenantSettingsQuery (already cached, already clamped by FirmSettingBounds). ClientPortal is L5 and Tenancy L2, so this is a legal downward dependency and no contract is needed.
2. Keyed by tenant id, never Tenant::current(): PortalSessionWindow::open runs at portal.exchange, which sits outside the ResolveClientPortalGrant group on purpose, so no tenant is current there. Both call sites already hold the grant, so both pass $grant->tenant_id.
3. PortalSessionWindow::open() and extend() take the limits as a parameter, keeping the window a pure value object. Its private Config reads and its MIN/MAX constants go: the bounds now live in FirmSettingBounds and the resolver returns an already-clamped value, so keeping them would be a second declaration of the same numbers.
4. The one invariant that is a relationship between the two settings rather than a bound on one - the ceiling can never be tighter than a single idle window - moves into PortalSessionLimits, which is now the only place that sees both numbers together.
5. Tests: a firm shortening the idle window gets it at redemption (the open() path, where no tenant is current - this is the test that catches the trap); a longer value is rejected on save and clamped on read; the absolute window is never below the idle one; a firm that sets nothing still gets the platform pair.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Both traps in the task description were real and both are now covered.

PortalSessionLimits is a readonly value object resolved by tenant id, so PortalSessionWindow stays a pure value object with no container, no config reads and no current tenant. Its MIN/MAX constants are gone: FirmSettingBounds owns those numbers now and the resolver returns an already-clamped value, so keeping them would have been a second declaration of the same band. The one rule that is a relationship between the two settings rather than a bound on either — the ceiling can never be tighter than a single idle window — moved into PortalSessionLimits::of(), which is the only place that sees both numbers.

The redemption test needed work to be worth anything. provisionWorkspace() makes a tenant current for the whole test process, so a lookup keyed on Tenant::current() would have passed in the suite and still been wrong in production, where portal.exchange runs outside the resolver middleware and no tenant is current. The test now calls Tenant::forgetCurrent() first, which is the honest simulation of a client arriving from their email. Confirmed it has teeth by temporarily keying the controller off Tenant::current()?->id ?? 0: the test fails, and passes again on the real code.

Found and fixed a bug I had introduced in 54.1 while doing this. IntegerBound::resolve() returned the platform value unclamped when a firm had chosen nothing, so a misconfigured PORTAL_SESSION_IDLE_MINUTES of 0, a negative, or 1440 would have passed straight through to the portal — the previous PortalSessionWindow clamped it into [1, 60] and that protection was quietly lost. The three existing PortalSessionLifetimeTest cases for exactly those values caught it. platform() now clamps into the same band, which also means the UI shows the default that actually applies rather than the raw configured value. min() and max() deliberately read the raw closure to avoid recursing through clamp().

Validation: pest tests/Feature/Portal/FirmPortalSessionWindowTest.php => 6 passed. Portal/ClientPortal/Tenancy/Unit/Architecture/Platform => 694 passed. Dossiers/Questionnaires/Retention/Localization/Security/Auth => 402 passed. pint and vp check clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A firm can shorten its client portal sessions, and the shorter window applies from the moment a client opens their link.

PortalSessionWindow now receives a PortalSessionLimits value object resolved per firm by tenant id rather than reading config itself, which keeps it a pure value object and works at link exchange, where no tenant is current. Its MIN/MAX constants moved out to FirmSettingBounds; the ceiling-not-tighter-than-one-idle-window invariant moved into PortalSessionLimits, the only place that sees both numbers.

Verified with 6 tests covering redemption as a genuine guest request, the window continuing to apply as the client works, an unset firm still getting the platform pair, a hand-edited longer window being clamped on read, a longer one being rejected on save, and the ceiling invariant. The redemption test was checked against a deliberately broken implementation to confirm it discriminates rather than passing by accident.

Also fixed a clamping hole introduced in 54.1: an inherited platform value was returned unclamped, so a misconfigured idle window would have reached the portal. 694 tests across the portal and tenancy suites, 402 across the rest, pint and vp check clean.
<!-- SECTION:FINAL_SUMMARY:END -->
