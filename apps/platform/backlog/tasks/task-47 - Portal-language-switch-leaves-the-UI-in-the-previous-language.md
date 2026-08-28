---
id: TASK-47
title: Portal language switch leaves the UI in the previous language
status: Done
assignee: []
created_date: '2026-08-28 08:33'
updated_date: '2026-08-28 08:39'
labels: []
dependencies: []
priority: high
type: bug
ordinal: 105000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Switching language on the client portal changes dates and form-widget chrome but leaves every translated string in the language of the first page load.

Root cause: the shared `translations` dictionary is an `Inertia::once()` prop keyed only by its prop name (LocaleProps). `locale` beside it is a regular prop, so it is re-resolved on every response. When PortalLocaleSwitcher writes the locale cookie and calls `router.reload()`, that is a full visit, so the client sends `X-Inertia-Except-Once-Props: translations` and the server skips resolving the dictionary. The client keeps the dictionary from its first load forever.

The result is a split UI: `locale` is live, so `useFormatters` and React Aria's I18nProvider (dates, the DatePicker calendar) follow the new language, while every `t()` string stays frozen at whatever the first visit resolved. That is the whole reported symptom - there is no second bug in the form components.

Staff are unaffected only because LocaleController flashes `inertia.refresh.translations`, which LocaleProps turns into `->fresh()`. Portal guests have no such endpoint (they are not signed in), so nothing sets the flag. Any other locale change path - Accept-Language, a cookie written outside the controller - has the same hole.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Switching language on the portal re-delivers the dictionary for the new locale, with no session flag involved
- [x] #2 A repeat visit at an unchanged locale still leaves the ~100 KB dictionary off the wire
- [x] #3 The staff language switch keeps working without LocaleController flashing a refresh flag
- [x] #4 A regression test covers the portal guest switch, driven by the once-prop keys the server actually advertises rather than a hardcoded header
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Fix: LocaleProps now ships the dictionary as `Inertia::once(...)->as("translations.{$locale}")`. The locale in the once() key means a language change always misses the client's remembered entry, so the server resolves the new dictionary — no session flag, and it covers the portal, Accept-Language, and bare-cookie paths equally.

Removed the `->fresh(session()->pull('inertia.refresh.translations'))` workaround and the matching flash in LocaleController; the key now carries the signal. `__invoke` no longer needs the Request, so it takes just the locale.

Round trip checked against the client implementation, not assumed: @inertiajs/core's `mergeOncePropsIntoResponse` iterates the *response's* onceProps only, so `translations.en` is dropped once a page advertises `translations.nl`. The client therefore never offers both keys at once, which would have made the switch back to English silently skip the prop. Pinned with an assertion.

Recorded the trap as a project rule in .ai/rules/shared-props.md.

Note: tests/Feature/Portal/PortalUploadQuotaTest.php:161 fails on develop as well (verified by stashing) — a locale leak in that test, unrelated to this work and left alone.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Keyed the shared translations once() prop by locale so a language switch can no longer collide with the dictionary the client already holds, which is what left every t() string frozen at the first page load while locale-driven output (dates, React Aria) followed the switch. Dropped the session-flash refresh workaround it replaces. Verified with a new feature test that drives the portal guest switch from the once-prop keys the server actually advertises and asserts both directions (en to nl and back); tests/Feature/Localization/LocaleTest.php 21 passed, and 389/390 across Localization, Portal, Platform and Settings — the single failure pre-exists on develop.
<!-- SECTION:FINAL_SUMMARY:END -->
