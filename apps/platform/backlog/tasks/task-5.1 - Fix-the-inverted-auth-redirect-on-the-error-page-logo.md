---
id: TASK-5.1
title: Fix the inverted auth redirect on the error page logo
status: Done
assignee:
  - cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 12:40'
labels: []
dependencies: []
references:
  - resources/js/pages/error.tsx
parent_task_id: TASK-5
priority: high
type: bug
ordinal: 18000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
On the error page the logo link resolves to the login route when the user is authenticated and the dashboard when they are not - the ternary is inverted. The button lower down the same component has it the right way round.

A signed-in user who hits a 403 or 404 and clicks the logo is bounced to the login screen.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 An authenticated user clicking the error page logo lands on the dashboard
- [x] #2 A guest clicking the error page logo lands on login
- [x] #3 A test covers both branches so the two links cannot drift apart again
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Extract a single home destination in error.tsx from isAuthenticated (dashboard when signed in, login when guest) for both logo Link and LinkButton.
2. Add a vitest covering both auth branches so the two links cannot drift (shared helper or component render).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verification: vendor/bin/sail npm test -- resources/js/pages/tests/error-home-route.test.ts → 2/2; vendor/bin/sail artisan test --compact tests/Feature/Platform/InertiaErrorPageTest.php → 4/4.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Fixed inverted error-page logo auth redirect. Logo and CTA now share errorPageHomeRoute(isAuthenticated): authenticated → dashboard (/), guest → login. Vitest covers both branches so the links cannot drift. InertiaErrorPageTest still green.
<!-- SECTION:FINAL_SUMMARY:END -->
