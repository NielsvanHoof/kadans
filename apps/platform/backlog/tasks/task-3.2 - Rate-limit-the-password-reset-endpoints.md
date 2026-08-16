---
id: TASK-3.2
title: Rate limit the password reset endpoints
status: Done
assignee:
  - '@claude'
created_date: '2026-08-06 09:50'
updated_date: '2026-08-06 10:36'
labels: []
dependencies: []
references:
  - app/Providers/FortifyServiceProvider.php
  - config/fortify.php
documentation:
  - docs/security.md
parent_task_id: TASK-3
priority: high
type: bug
ordinal: 10000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Only the login, two-factor and passkeys rate limiters are registered. Both `password.email` and `password.update` carry only the guest middleware, so neither is throttled.

Unbounded POSTs to /forgot-password mail-bomb any known staff address and burn SES reputation and quota. Combined with the Host header issue it allows spraying reset emails at every address in a firm. /reset-password is likewise unbounded for token guessing - the tokens are strong, so that side is denial of service and enumeration rather than takeover.

docs/security.md currently lists the three existing limiters and describes rate limiting as complete, so the doc needs correcting too.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 POST /forgot-password is throttled and returns 429 once the limit is exceeded
- [x] #2 POST /reset-password is throttled and returns 429 once the limit is exceeded
- [x] #3 Feature tests cover both limits
- [x] #4 docs/security.md lists the full set of registered limiters
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Establish that Fortify's config('fortify.limiters.*') hook only covers login/two-factor/passkeys — routes.php never reads a limiter key for password.email/password.update, so config/fortify.php cannot wire throttling for these routes on its own.
2. Register two new named rate limiters in FortifyServiceProvider: 'reset-password-email' (stacked limits: 3/min per email + 10/min per IP, to stop both single-address mail-bombing and cross-address spraying) and 'reset-password-update' (10/min per IP, since tokens are strong so this is DoS/enumeration protection).
3. Attach 'throttle:reset-password-email' / 'throttle:reset-password-update' directly to the already-registered password.email / password.update routes by name, since Fortify gives no other hook.
4. Update docs/security.md to list the full set of registered limiters, including that the two password-reset ones are wired via direct route middleware rather than fortify.limiters.
5. Add feature tests asserting both endpoints return 429 once their limit is exceeded.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Attaching middleware to already-registered routes by name (Route::getRoutes()->getByName('password.email')?->middleware(...)) initially had no effect: RouteCollection::nameList is only populated at Route::add() time, but Fortify calls ->name() on the route object AFTER add() runs, so the name index is stale until refreshNameLookups() runs — which Laravel only does lazily, inside toSymfonyRouteCollection(), the first time routes are matched against a real request. During a ServiceProvider::boot() (no request has been dispatched yet), getByName() silently returned null for every route. Fixed by calling Route::getRoutes()->refreshNameLookups() before the getByName() lookups in configurePasswordResetThrottling(). Confirmed the fix with 'vendor/bin/sail artisan route:list --name=password -v' showing throttle:reset-password-email / throttle:reset-password-update on the two routes, then with direct pest --agent probes hitting 429 on the 4th /forgot-password POST and the 11th /reset-password POST.

Verified provider boot order empirically (not just by reading Application::registerConfiguredProviders()): package-manifest providers (Laravel\Fortify\FortifyServiceProvider) do boot before bootstrap/providers.php entries (our App\Providers\FortifyServiceProvider), so Fortify's routes exist by the time ours runs — the refreshNameLookups() gap above was the only real blocker.

Verification: vendor/bin/sail artisan test --compact tests/Feature/Auth/PasswordResetTest.php -> 8/8 passed. Full suite: vendor/bin/sail artisan test --compact -> 755/755 passed. vendor/bin/sail bin pint --format agent on all changed files -> passed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Registered 'reset-password-email' (3/min per email + 10/min per IP) and 'reset-password-update' (10/min per IP) rate limiters in FortifyServiceProvider, and attached them directly to the password.email/password.update routes by name since Fortify has no config-driven limiter hook for these two routes (unlike login/two-factor/passkeys). Had to add an explicit Route::getRoutes()->refreshNameLookups() call first — route name lookups are only rebuilt lazily on the first real request match, so getByName() silently returned null during boot without it. Updated docs/security.md to list the full set of registered limiters and explain the two routes are wired differently. Verified with new feature tests (429 after exceeding each limit) and a full suite run: 755/755 passing.
<!-- SECTION:FINAL_SUMMARY:END -->
