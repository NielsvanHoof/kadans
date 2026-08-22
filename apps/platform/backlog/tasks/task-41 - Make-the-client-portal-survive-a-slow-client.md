---
id: TASK-41
title: Make the client portal survive a slow client
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-08-21 08:12'
updated_date: '2026-08-21 08:41'
labels:
  - portal
  - frontend
  - backend
dependencies: []
priority: high
type: feature
ordinal: 94000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The client portal is the only flow whose user has no account, no password, and no support channel, and it is the least forgiving surface in the app. config/portal.php sets session_lifetime_minutes to 15 and that window is absolute: ClientPortalSessionController stamps portal.expires_at once and ResolveClientPortalGrant only ever compares it. A client working through a ten-item questionnaire on a phone routinely crosses 15 minutes, and their next request is abort(404) rendered by the generic app error page - which, because auth.user is null, shows the app logo linking to login and a 'Go to login' button. A client with no account is told to sign in, with no indication that their submitted work was saved or that the link in their email still works. Three smaller gaps compound it: clients cannot retrieve or verify a file they uploaded (PortalUploadedDocumentData exposes no download path); the empty state says 'contact :firm' while supplying no contact details; and nothing warns the client before the session ends.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 An active portal client is never signed out mid-questionnaire: each portal request extends an idle deadline
- [x] #2 An absolute session ceiling is set once at exchange and is never extended, so a stolen session still dies
- [x] #3 Neither deadline ever exceeds the grant's own expires_at
- [x] #4 A session that ends lands on a firm-branded portal page explaining that submitted work is saved and the emailed link still works, not the app 404
- [x] #5 Session end distinguishes a timeout from revoked access; every other portal denial stays a 404
- [x] #6 A client can download a file they uploaded, subject to the same malware verdict as staff downloads, refused as 404
- [x] #7 The portal names a firm contact (responsible user, else grant creator) instead of an unactionable 'contact the firm'
- [x] #8 The client is warned before the session ends and can deliberately keep it open, bounded by the absolute ceiling
- [x] #9 ProductionConfigurationValidator fails boot when the portal idle window exceeds session.lifetime
- [x] #10 Backend Pest tests and frontend Vitest tests cover all of the above; EN and NL copy added
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. config/portal.php: replace session_lifetime_minutes with session_idle_minutes (15) + session_absolute_minutes (240); sweep .env.example, .env.ci, phpunit.xml, docs, infra for the old key.
2. New app/ClientPortal/Support/PortalSessionWindow.php value object owning both deadlines and all clamping (idle 1-60, absolute idle-1440, both capped by grant->expires_at).
3. New app/ClientPortal/Enums/PortalSessionEndReason.php (TimedOut / AccessEnded).
4. ResolveClientPortalGrant: read+validate both deadlines, extend the idle one on every pass, stash the window on request attributes, and redirect to portal.ended for the two disclosable cases only. Keep abort(404) for no session, unresolvable grant, missing tenant.
5. ClientPortalSessionController: build the window, write grant id + both deadlines + portal.firm_name. Keep the forced X-Requested-With header.
6. New ClientPortalEndedController + ClientPortalSessionExtendController; new PortalSessionData; PortalFirmData gains contactName/contactEmail resolved from dossiers.responsible_user_id falling back to the grant creator.
7. Extract app/Dossiers/Support/UploadedDocumentResponse.php (streaming + UNTRUSTED_FILE_HEADERS) and app/Dossiers/Actions/GuardUploadedDocumentDeliveryAction.php (malware verdict + audit) out of UploadedDocumentController; add ClientPortalUploadController::download using both, aborting 404 not 403.
8. ProductionConfigurationValidator: portal idle window must be strictly below session.lifetime.
9. Frontend: portal/ended page, portal-session-notice + use-portal-session-countdown hook, per-file download link in PortalUploadedFile, firm contact copy, optional firmName on PortalShell.
10. EN/NL copy, Pest feature tests, Vitest tests, docs/security.md, and a record-rule note for the session semantics and the deliberate 404 exception.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verified with composer ci:check: 1379 Pest tests / 7489 assertions, 498 Vitest tests, phpstan 0 errors, pint + rector + biome + prettier clean, frontend line coverage 45.62% (floor 41%).

Evidence per area:
- Sliding window: tests/Feature/Portal/PortalSessionLifetimeTest.php (14 tests) uses travel() to prove each request extends the idle deadline, that eight consecutive 10-minute gaps never sign the client out, that the ceiling still lands while the client is active, that the idle deadline clamps to the ceiling, and that neither deadline outlives grant->expires_at. Also covers the clamp table (0/-5/1440/20 minutes) and that a pre-upgrade session with no ceiling is ended rather than trusted.
- Ended page: tests/Feature/Portal/PortalSessionEndedPageTest.php (8 tests) proves the component is portal/ended (not the app error page), the two reasons are distinguished, the reason is flashed not stored, no-session and deleted-grant still 404, and the hardening headers survive. Confirmed against the running app with pest --agent asserting component('portal/ended').
- Client download: tests/Feature/Portal/PortalUploadedDocumentDownloadTest.php (8 tests) plus a portal case added to tests/Feature/Security/MalwareScanGateTest.php, which owns the S3 mock harness - the portal refusal is a 404 and is still audited as FileBlockedByMalwareScan.
- Warning UI: 8 Vitest tests on use-portal-session-countdown, 5 on PortalSessionNotice, 2 SSR tests proving the countdown touches no browser global during render, and 4 on the per-file download link.
- Staff regression: all 180 tests in tests/Feature/Dossiers + tests/Feature/DocumentRequests pass unchanged after extracting UploadedDocumentResponse and GuardUploadedDocumentDeliveryAction out of UploadedDocumentController.

Decisions taken during implementation:
- PortalFirmData.contactName/contactEmail are non-nullable, not optional. PHPStan flagged the defensive ?User: client_access_grants.created_by is constrained()->cascadeOnDelete() and User has no soft deletes, so a live grant always has a live creator. Encoding that removed a null branch from the query, the DTO and the React tree.
- Two architecture guardrails caught the new surface and were updated with the mechanism rather than worked around: CONTROLLER_NON_REST_ACTIONS (ClientPortalUploadController::download) and AUTHORIZATION_NOT_VIA_GATE (the portal download's grant + parent match + malware verdict, and why it carries no RequirePassword).
- createPortalDossierWithGrant() was promoted from a file-local helper in ClientPortalTest to tests/Pest.php, since four portal test files now need it.
- Session end deliberately breaks the portal's 'every denial is a 404' rule for exactly two cases. Safe because SESSION_GRANT_ID is only written after a successful token exchange and sits behind a signed cookie - the same argument the existing rules use for not timeboxing the middleware. Recorded in .ai/rules/client-portal.md and docs/security.md so it is not 'fixed' back.

Not done: no real-browser screenshot of the ended page. Playwright's browser binaries are not installed, and decision-5 accepts no browser E2E for v1.0, so installing them was left to the user.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Replaced the client portal's single absolute 15-minute session with a sliding idle window (portal.session_idle_minutes, renewed on every portal request) plus an absolute ceiling stamped once at redemption (portal.session_absolute_minutes), both clamped by the grant's own expires_at and all owned by the new PortalSessionWindow. An active client filling in a long questionnaire is no longer signed out; an abandoned or stolen session still dies on schedule.

A session that does end now redirects to a firm-branded portal/ended page that distinguishes a timeout ('your work is saved, re-open the emailed link') from withdrawn access, instead of the app-wide error page that offered an accountless client a 'Go to login' button. This is the one deliberate exception to concealing portal denials as 404s; every other case - no session, unresolvable grant id, missing tenant - still 404s.

Also: clients can download the files they uploaded, through the same UploadedDocumentResponse hardening and the same GuardUploadedDocumentDeliveryAction malware verdict as staff (both extracted from UploadedDocumentController), refused as 404 rather than staff's 403; the portal names a reachable staff contact (responsible user, else the grant creator) instead of an unactionable 'contact the firm'; and a pre-expiry banner warns the client with a deliberate 'Keep my session open' action that the ceiling still bounds.

Verified with composer ci:check green end to end: 1379 Pest tests (7489 assertions) including 30 new portal tests, 498 Vitest tests including 19 new ones, phpstan 0 errors, pint/rector/biome/prettier clean. The ended page was additionally confirmed against the running app with pest --agent. ProductionConfigurationValidator now fails boot if the idle window reaches session.lifetime. docs/security.md and .ai/rules/client-portal.md updated.
<!-- SECTION:FINAL_SUMMARY:END -->
