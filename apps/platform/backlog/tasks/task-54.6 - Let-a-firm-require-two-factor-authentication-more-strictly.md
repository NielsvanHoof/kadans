---
id: TASK-54.6
title: Let a firm require two-factor authentication more strictly
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-04 20:38'
updated_date: '2026-09-06 11:43'
labels: []
dependencies:
  - TASK-54.1
parent_task_id: TASK-54
priority: high
type: feature
ordinal: 118000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two-factor enforcement is one platform-wide setting (SECURITY_TWO_FACTOR_ENFORCEMENT, default all). A firm that wants every staff member enrolled cannot ask for it, and a firm that has negotiated a different posture cannot have one either.

This is the riskiest subtask under TASK-54 and should land last, because getting it wrong weakens authentication for a whole firm.

The layering makes it the only area needing an inversion. RequireTwoFactorAuthentication lives in App\Accounts, which tests/Pest.php forbids from using App\Tenancy, so it cannot read tenant settings directly. App\Tenancy\Contracts\SupportSessionState is the precedent to follow: the lower domain owns the contract, the higher one implements and binds it.

Two findings from planning that the implementation depends on:

The stricter-only clamp has to be applied by the middleware itself rather than by the contract implementation. Done that way, the effective level is always at least the platform floor by construction, so no implementation — buggy, mocked or hostile — can weaken it, and ProductionConfigurationValidators refusal to boot production with enforcement off keeps covering every firm.

There is no AccountsServiceProvider today; Accounts wiring lives in AppServiceProvider and FortifyServiceProvider. Whichever provider binds the default, provider order in bootstrap/providers.php decides which binding wins and fails silently if reordered, so it needs pinning with a test.

Two scoping notes: the ordering off < by_permission < all_staff is only a total order while the gated permission key stays platform-level, so that key must not become per-firm. And grace_days is part of enforcement in practice but is not covered by that ordering — keep it platform-only for now unless the user asks otherwise.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A firm administrator can require two-factor authentication more strictly than the platform default, or leave it inherited
- [x] #2 A firm cannot weaken enforcement below the platform default, on save or by editing the row directly
- [x] #3 Staff on a route with no current firm are unaffected and see no error
- [x] #4 Enforcement is still skipped entirely when the two-factor feature is disabled
- [x] #5 The binding that supplies the firm override is pinned by a test, so reordering providers fails loudly
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. App\Accounts\Contracts\TwoFactorEnforcementSource - the lower domain owns the contract, exactly as Tenancy owns SupportSessionState for Platform. One method, forCurrentContext(): ?TwoFactorEnforcement, returning null for "no override". Deliberately takes no Tenant parameter: naming that type is the thing Accounts is forbidden to do.
2. App\Accounts\Support\InheritPlatformTwoFactorEnforcement - a null object returning null, so an Accounts-only context needs no branch in the middleware.
3. App\Tenancy\Support\TenantTwoFactorEnforcementSource - reads the firm override through ResolveTenantSettingsQuery, returns null when no tenant is current.
4. New App\Accounts\AccountsServiceProvider binds the null object; TenancyServiceProvider binds the real one. Provider order in bootstrap/providers.php decides which wins and fails silently if reversed, so Accounts is registered before Tenancy and a test pins the resolved implementation.
5. The middleware applies the clamp itself: strictest(platform, override ?? platform). Applied by the consumer rather than trusted from the collaborator, the effective level is at least the platform floor BY CONSTRUCTION - no implementation, mocked or hostile or simply wrong, can weaken it, so ProductionConfigurationValidators refusal to boot with enforcement off keeps covering every firm without becoming tenant-aware.
6. grace_days stays platform-only, as scoped in the task.
7. Tests: a firm tightening past the platform level; a firm Off in the database still enforced; no current tenant unaffected; Fortify feature off still skips; and the binding pinned.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
The inversion follows SupportSessionState exactly: Accounts owns TwoFactorEnforcementSource, Tenancy implements it as TenantTwoFactorEnforcementSource. The contract deliberately takes no Tenant argument, because naming that type is the one thing Accounts is forbidden to do; an implementation decides for itself what "current" means. The arch test passes unchanged, with no new entry in any ignore list.

Created AccountsServiceProvider, which did not exist - Accounts wiring lived in AppServiceProvider and FortifyServiceProvider. It binds a null object (InheritPlatformTwoFactorEnforcement) so Accounts stands up alone, and Tenancy rebinds it. Last binding wins, so Accounts is registered before Tenancy in bootstrap/providers.php. Verified that ordering is load-bearing by reversing it: the binding test plus three behavioural tests fail, so a reorder cannot land quietly.

The clamp is applied by the middleware, not trusted from the source, and there is now a test that proves what that buys: a source deliberately returning Off while the platform says AllStaff is ignored and the user is still diverted to enrolment. A wrong or hostile implementation can fail to tighten; it cannot loosen. ProductionConfigurationValidator is untouched and still guarantees production never runs with enforcement off, for every firm, without knowing firms exist.

Two things the tests corrected in my understanding:

1. My first version of the "no current firm" test used route("dashboard") and expected the firm setting not to apply. It does apply, and that is correct: InitializeTenantFromSession makes a firm current on the app routes too when the user belongs to exactly one. The genuine no-firm case is a user with none, so the test now asserts that one firms requirement never reaches a user outside it, and a second test pins that a firms own members ARE covered on the app routes.

2. A helper using a mass update left the settings cache stale, because invalidation hangs off TenantSetting saved and the query builder fires no model events. Production writes go through the model, so this was a test-only artifact - the helper now updates through the model, which is also the honest path.

grace_days stays platform-only as scoped, and docs/security.md now records the floor-not-ceiling rule, why the ordering is only total while the gated permission key stays platform-wide, and that grace_days is not covered by it.

Validation: pest tests/Feature/Accounts/FirmTwoFactorEnforcementTest.php => 10 passed. Accounts/Auth/Settings/Security/Tenancy/Unit/Architecture/Platform => 703 passed. ClientPortal/Portal/Dossiers/Retention/Localization/Questionnaires => 422 passed. pint and vp check clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A firm can require two-factor authentication more strictly than the platform default, and cannot go the other way.

RequireTwoFactorAuthentication lives in Accounts, which may not read Tenancy, so Accounts owns a TwoFactorEnforcementSource contract and Tenancy implements it - the same inversion, in the same direction, as SupportSessionState. A new AccountsServiceProvider binds a null object so the domain stands up alone; Tenancy rebinds the firm-aware one.

The middleware applies strictest() against the configured level itself rather than trusting what it is handed, which makes the floor hold by construction rather than by convention.

Verified with 10 tests: a firm tightening past the platform level, an inherited firm behaving exactly as before, a row saying off still enforced, a firm catching staff by_permission would miss, a user outside the firm untouched, a firms own members covered on the app routes, the feature switch still winning, an enrolled user let through, the binding pinned, and a deliberately hostile source proved unable to weaken enforcement. Reversing the provider order was checked to fail loudly. 703 tests across the auth and tenancy suites, 422 across the rest, pint and vp check clean.
<!-- SECTION:FINAL_SUMMARY:END -->
