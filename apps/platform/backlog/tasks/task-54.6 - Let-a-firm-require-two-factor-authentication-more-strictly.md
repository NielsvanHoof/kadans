---
id: TASK-54.6
title: Let a firm require two-factor authentication more strictly
status: To Do
assignee: []
created_date: '2026-09-04 20:38'
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
- [ ] #1 A firm administrator can require two-factor authentication more strictly than the platform default, or leave it inherited
- [ ] #2 A firm cannot weaken enforcement below the platform default, on save or by editing the row directly
- [ ] #3 Staff on a route with no current firm are unaffected and see no error
- [ ] #4 Enforcement is still skipped entirely when the two-factor feature is disabled
- [ ] #5 The binding that supplies the firm override is pinned by a test, so reordering providers fails loudly
<!-- AC:END -->
