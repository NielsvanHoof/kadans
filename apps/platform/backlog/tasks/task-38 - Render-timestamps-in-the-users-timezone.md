---
id: TASK-38
title: Render timestamps in the user's timezone
status: Done
assignee: []
created_date: '2026-08-14 11:50'
updated_date: '2026-08-14 13:02'
labels: []
dependencies:
  - TASK-37
ordinal: 91000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
All absolute timestamps render in UTC wall clock, so a Dutch (CEST) user sees "Laatst geopend 14-08-2026, 11:18:17" when their clock reads 13:18. resources/js/lib/format-date-time.ts hardcodes timeZone: 'UTC' to keep SSR and the browser from diverging during hydration; the fix must keep them agreeing while showing local time, which means the zone has to be resolved server-side and delivered as an Inertia prop rather than detected at render. Mirrors the existing locale architecture (user column -> cookie -> config fallback) so timezone becomes a sibling of an established pattern. A persisted column is also the only thing that can fix the three invite/reminder mails, which have no request and no cookie.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Absolute timestamps render in the viewer's resolved timezone, and the tooltip names the zone
- [x] #2 Calendar-only values (due_date) render the same day in every timezone — they are not zone-converted
- [x] #3 The zone resolves user column -> cookie -> app.display_timezone, and an invalid or hostile cookie falls back instead of throwing
- [x] #4 config/app.php timezone stays UTC so stored timestamps are unaffected
- [x] #5 Users can set their timezone in settings, and the choice outranks browser detection
- [x] #6 Invite and reminder mail renders times in the recipient's zone with the zone named
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. config/app.php: add display_timezone (env APP_DISPLAY_TIMEZONE, default Europe/Amsterdam). app.timezone STAYS UTC — changing it would make now() return local wall-clock and corrupt the naive 'timestamp without time zone' columns. Pin in .env.example and phpunit.xml.
2. app/Accounts/Support/DisplayTimezones.php: validate via ICU (IntlTimeZone::getCanonicalID + isSystemID), NOT new DateTimeZone() and NOT timezone_identifiers_list(). Measured: new DateTimeZone('CEST') SUCCEEDS but Intl.DateTimeFormat throws RangeError on it; ALL_WITH_BC contains 4 tzdata filenames (Factory, leapseconds, localtime, tzdata.zi) that Intl also rejects, so Laravel's timezone:all_with_bc rule has the same hole. ICU rejects all of them and accepts real browser outputs (Asia/Calcutta, US/Pacific) that PHP's lists reject. Also deny 'Factory' explicitly. Same class holds the curated picker options.
3. ResolveApplicationTimezoneAction mirroring ResolveApplicationLocaleAction: user column (via getAttributes() to survive shouldBeStrict) -> cookie -> config -> UTC. No Accept-Language rung; language does not imply a zone. Invalid/hostile values fall through silently, never throw.
4. TimezoneProps shared prop + HandleInertiaRequests wiring. bootstrap/app.php:65 encryptCookies(except:) MUST gain 'timezone' or the plaintext JS cookie is silently dropped. Update global.d.ts and InertiaPropContractTest allowlist. Do NOT add timezone to auth.user — SharedInertiaDataTest pins that shape exactly.
5. use-timezone-sync hook called from aria-locale-provider (the one guaranteed wrapper on every page). Detect via Intl.DateTimeFormat().resolvedOptions().timeZone, write cookie, skip if unchanged. Never call resolvedOptions() at render time — SSR has no browser zone. Accept one-render staleness on a brand-new session; the Amsterdam default makes it invisible.
6. formatDateTime(iso, locale, timeZone) — required param so a missed call site is a tsc error. formatDate KEEPS its UTC anchor (calendar days must not shift). New use-formatters hook returns pre-bound formatters (useMemo — notification-list-item calls a formatter inside useEffect, and Biome's useExhaustiveDependencies would demand a stable identity). Migrate ~26 call sites.
7. users.timezone migration + model + settings selector on the existing settings/language page (no new tab). React Aria Select needs aria-label and id-keyed items.
8. Mail: member invite uses the recipient's stored zone; the two ClientPortal notifications mail clients (no user, no cookie) so they use app.display_timezone. Add (T) so the zone is named.
9. Tests: TZ in Vitest must use vi.stubEnv, not process.env (untyped -> TS2591). Hostile-cookie dataset. Assert calendar days do not shift.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented across 78 files.

Backend: config/app.php gains display_timezone (env APP_DISPLAY_TIMEZONE, default Europe/Amsterdam); app.timezone left at UTC so storage is untouched. New DisplayTimezones support class, ResolveApplicationTimezoneAction, TimezoneProps shared prop, users.timezone migration (reversible, verified), TimezoneController + TimezoneUpdateRequest, and User::preferredTimezone().

Frontend: formatDateTime gained a REQUIRED timeZone parameter — that choice turned 'did I catch every call site' into a compiler question, and tsc listed all 35 immediately. New useFormatters hook returns pre-bound formatters (memoised: notification-list-item calls a formatter inside useEffect, so an unstable identity would re-run it every render). 33 calls migrated across 23 files. New useTimezoneSync writes the detected zone to a cookie from aria-locale-provider, the one wrapper present on every page including portal/legal/error.

Three findings that changed the design mid-implementation:

1. ICU, not PHP, is the right validation authority. Measured: new DateTimeZone('CEST') SUCCEEDS while Intl.DateTimeFormat throws RangeError on it — so the constructor is unusable as a gate. And timezone_identifiers_list(ALL_WITH_BC) contains four tzdata FILENAMES (Factory, leapseconds, localtime, tzdata.zi) that Intl also rejects, so Laravel's own timezone:all_with_bc rule would have admitted a page-crashing value. ICU rejects all of them and additionally accepts Asia/Calcutta / US/Pacific, real browser outputs that PHP's lists reject. Gate is IntlTimeZone::getCanonicalID + isSystemID, plus an explicit Factory rejection.

2. ECMA-402 forbids combining dateStyle/timeStyle with component options such as timeZoneName — Intl throws 'TypeError: Invalid option'. The tooltip zone label therefore needs a second zone-only formatter and concatenation, now encapsulated in formatDateTimeWithZone with a test asserting it does not throw.

3. SSR is configured but DORMANT: config/inertia.php says enabled, but there is no resources/js/ssr.tsx and vite.config.ts builds only app.tsx, so Inertia falls back to client rendering. The prop-based design is unchanged (it is what keeps this correct when SSR is switched on, and what lets mail format), but the hydration-mismatch risk is latent rather than live. Worth a separate decision about whether SSR should actually be wired up.

Two contract tests caught real omissions, as designed: AuthorizationCoverageTest required timezone.update to declare its non-Gate mechanism, and DomainStructureTest enforces SelectGroup directly under SelectContent — which my grouped dropdown violated. Replaced grouping with a flat list labelled 'Amsterdam (GMT+02:00)' and ordered by offset, which is better UX anyway and removed the need for group translation keys.

Docs: cookie added to docs/legal/cookie-inventory.md and both public statements (resources/legal/{en,nl}/cookies.md), per the project's three-place cookie process. APP_DISPLAY_TIMEZONE pinned in phpunit.xml so a developer's .env cannot change what the tests assert.

Decided AGAINST a ProductionConfigurationValidator entry: DisplayTimezones::default() already validates the config value and degrades to UTC, so a typo'd env var cannot throw — there is no unsafe default to guard.

Verification: Pest 1165 passed / 6699 assertions (26 new in TimezoneTest, including a 9-case hostile-cookie table and a 3-case ICU-alias table); Vitest 428 passed (new use-formatters, use-timezone-sync, timezone-select suites); tsc 0 errors; Pint, Biome, PHPStan, Rector all clean; migration applied and rolled back against Postgres.

Browser-verified in real Chromium (Playwright binaries were missing; installed chromium only — playwright itself was already at ^1.62.1, so package.json and package-lock.json are unchanged. An npm run inside the Sail container rewrote the lockfile 'name' field to 'html' because it derives it from /var/www/html; reverted).

The first browser run FAILED in a useful way: the timezone section was entirely absent from the page even though every Vitest and Pest test passed. Cause was stale Vite assets — the browser serves public/build, and the new component had never been compiled. Ran npm run build and it rendered. Worth knowing: Vitest compiles from source, so a green frontend suite says nothing about what the running app is actually serving.

After the build, two browser checks pass:
1. /settings/language renders the Timezone heading, description, and a Select showing 'Tokyo (GMT+09:00)' from the stored preference. Tokyo is outside the curated shortlist, so this also confirms the append-current-zone path. assertNoJavaScriptErrors passes, which is the check that would have caught a missing React Aria aria-label flooding useLabel warnings.
2. The dossier show page — the exact screen from the bug report — renders 'LAATST GEOPEND 14-08-2026, 13:18:17' for a last_client_opened_at stored as 2026-08-14 11:18:17 UTC, with assertDontSee('11:18:17') confirming the old UTC value is gone. No JavaScript errors.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Timestamps now render in the viewer's own timezone. The zone is resolved server-side (users.timezone -> browser-reported cookie -> app.display_timezone, default Europe/Amsterdam) and delivered as an Inertia prop, so the server and the browser always format the same instant identically rather than the client discovering a zone at render time. app.timezone stays UTC, so nothing about storage changed.

formatDateTime gained a required timeZone parameter; formatDate deliberately did NOT, because it renders calendar days (due_date) that must not shift across zones. 33 call sites migrated to a new useFormatters hook that binds locale and zone once. A users.timezone column plus a settings selector make the choice explicit and portable, and it is what lets the three invite/reminder mails — which have no request and no cookie — finally render in a real zone with the abbreviation named.

Validation goes through ICU rather than PHP's zone list, because the value reaches Intl.DateTimeFormat in the browser and the two disagree in both directions: new DateTimeZone('CEST') succeeds where Intl throws RangeError, and timezone_identifiers_list(ALL_WITH_BC) admits four tzdata filenames that Intl rejects — so Laravel's built-in timezone rule would have let a page-crashing value through.

Verified: Pest 1165 passed / 6699 assertions, including 26 new tests covering every resolution rung, a 9-case hostile-cookie table (CEST, raw offsets, tzdata filenames, traversal, script injection) all falling back without throwing, a 3-case ICU-alias table, persistence precedence, and rendered mail asserting '15 July 2026, 21:00 (JST)'. Vitest 428 passed, including TZ-pinned DOM tests and a stable-identity guard on the hook. tsc 0 errors; Pint, Biome, PHPStan, Rector clean; migration applied and rolled back on Postgres. Confirmed the originally reported value: 14-08-2026 11:18:17 UTC now presents as 13:18:17.
<!-- SECTION:FINAL_SUMMARY:END -->
