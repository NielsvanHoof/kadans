# Cookie inventory (Qompose platform)

**Audit date:** 2026-08-11  
**Method:** Code review against `cookie-audit` + `eprivacy-essential-cookies` skills (Planet49 / ePrivacy Art. 5(3) / WP194).  
**Scope:** First-party cookies set by the Laravel + Inertia app. No third-party analytics/marketing trackers found in `resources/js`.

**Not legal advice.** Counsel should confirm classifications for NL (Autoriteit Persoonsgegevens / Telecommunicatiewet art. 11.7a).

## Inventory

| Name | Set by | Duration | Category | Consent needed? | Purpose |
| --- | --- | --- | --- | --- | --- |
| `qompose-session` | Laravel session (`config/session.php`) | 120 minutes idle (`SESSION_LIFETIME`) | Strictly necessary | No | Maintains authenticated (and guest CSRF-backed) session state |
| `XSRF-TOKEN` | Laravel CSRF middleware | Session | Strictly necessary | No | Cross-site request forgery protection; readable by JS for XHR |
| `remember_web_*` | Laravel auth “Remember me” | Persistent (Laravel recaller lifetime) | Strictly necessary (auth) | No — only when user opts into “Remember me” | Keeps the staff user signed in across browser restarts |
| `locale` | Client (`setClientCookie`) + `LocaleController` | 365 days | Functionality | Disclose; strict WP194 treats persistent preference as non-exempt | Stores UI language (`en` / `nl`) for guests and before account preference applies |
| `appearance` | Client (`setClientCookie` / appearance hooks) | 365 days | Functionality | Disclose; same note as locale | Stores light / dark / system theme for SSR-safe bootstrap (`HandleAppearance`) |
| `sidebar_state` | Staff UI (`sidebar.tsx`) | 7 days | Functionality | Disclose | Remembers sidebar expanded/collapsed for staff chrome |

Cookies `appearance`, `locale`, and `sidebar_state` are excluded from Laravel cookie encryption (`bootstrap/app.php`) so middleware/JS can read them.

## Storage beyond cookies

| Storage | Purpose | Notes |
| --- | --- | --- |
| `localStorage.appearance` | Theme preference mirror | Same purpose as `appearance` cookie |
| `localStorage.locale` | Locale preference mirror | Same purpose as `locale` cookie |

## Classification conclusions

1. **Strictly necessary (no consent banner required):** session, CSRF, remember-me.
2. **Functionality / preference (disclose in cookie statement):** locale, appearance, sidebar_state.
3. **No analytics / advertising cookies** in the current codebase → **no blocking consent CMP** for launch (per product plan). Re-audit before adding GA, PostHog, Meta Pixel, etc.
4. **Counsel open point:** whether persistent preference cookies need prior opt-in under Dutch practice. CNIL-style guidance often treats language/display as exempt; WP194 is stricter for multi-visit persistence. Document in the public cookie statement either way.

## Re-audit triggers

- New third-party scripts or tag managers
- New first-party cookies or LocalStorage keys used for tracking
- Change to session lifetime or remember-me behaviour
