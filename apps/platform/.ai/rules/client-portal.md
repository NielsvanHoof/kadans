---
paths:
  - 'app/ClientPortal/**'
---

# Client Portal

## Portal grants: hashed tokens exchanged for short server-side sessions
Clients never hold an account. Access is a `ClientAccessGrant`, scoped to one dossier or to a client: `Str::random(64)` stored **SHA-256 hashed** and marked `$hidden` — the plaintext exists only in the outgoing email.

Visiting the link exchanges the token for a server-side session capped at `portal.session_lifetime_minutes` and never beyond the grant's own expiry, with `regenerate(destroy: true)`. After exchange the controller forces `X-Requested-With` — this stops `StartSession` copying the redeemed bearer URL into `_previous.url`. Do not remove it.

`HardenClientPortalResponse` puts `no-store`, `no-referrer`, and `X-Robots-Tag: noindex, nofollow, noarchive` on every portal response, and is re-applied in the `bootstrap/app.php` exception responder so error pages stay hardened. Inertia history encryption is on.

Grants are revocable, `MassPrunable`, and record `last_used_at` under a row lock.

Redemption is device-checked once, in `ClientPortalSessionController` (not per request — the device cannot change mid-session). `RegisterClientPortalDeviceAction` compares a **coarse** fingerprint (`PortalDeviceFingerprint`: user agent with version numbers stripped + IPv4 /24 or IPv6 /64, hashed). Coarse on purpose: a precise fingerprint fires on every browser update and DHCP lease and the alert gets trained away. Only the hash is persisted. `portal.new_device_action` is `notify` (default) / `block` / `ignore`; `block` aborts **404**, never 403. One alert per grant — `new_device_seen_at` is the flag.

Portal submissions delegate to `DocumentRequestTransitions::canSubmit()` — clients may submit only when an item is `pending` or `rejected` and the dossier is not completed. Never duplicate that rule in an HTTP adapter or the UI.

The portal conceals cross-dossier probes as 404s via `abort()`, which deliberately does **not** reach `LogAccessDeniedAction` (only policy denials do). Keep it that way — otherwise probes flood the audit trail.

That applies to Form Requests too. A portal `authorize()` returning `false` yields **403** *and* writes an access-denied row, which is how the answer endpoint drifted from the upload one for a while. Where a request cannot reach what it names, return `true` and let the controller raise the concealed 404.

Cumulative portal uploads per dossier are capped by `DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER`. Per dossier, not per link: the cap protects a dossier's storage footprint, so a client link over three dossiers carries three of them.

## Which dossiers a link opens is decided in exactly one place
`ClientAccessGrant` carries a `scope`. A `dossier`-scoped grant opens the one dossier it names. A `client`-scoped grant opens every dossier the firm has shared with that client, re-evaluated per request — so a dossier shared next month is reachable by a link sent today, and a `draft` dossier is reachable by nothing.

`FetchReachableDossiersQuery` is the only definition of that, and `ResolveGrantedDossierQuery` is how adapters ask. It takes an id, not a `Dossier`, so a caller cannot resolve one through an unscoped query and hand it over already trusted. Never compare `$documentRequest->dossier_id` against `$grant->dossier_id`: that comparison used to live in eight places — two of them the authoritative re-check under the grant row lock — and each was the portal's entire authorization boundary at that point. `tests/Unit/PortalGrantDossierScopeTest.php` scans source and fails if it comes back, including for a new controller that skips the check altogether.

Reachability answers "may you see this", not "may you write to it". `DocumentRequestTransitions::canSubmit()` stays its own chokepoint — which is why a **completed** dossier remains reachable, read-only. Dropping one out of reach the moment staff finish it would sign a client out mid-questionnaire, the same failure the sliding session window exists to prevent.

Draft is the whole security boundary, and a durable one: a dossier is invisible until someone holding `client_access.grant` runs `ShareDossierWithClientAction`, and nothing moves a dossier back to Draft. Sharing is deliberately separate from minting a token — a standing link is a door, not a decision about what sits behind it — which is why `markAwaitingClient` has exactly one caller.

`POST portal/upload-tickets` takes a `dossier_id` and runs it through the chokepoint. It used to derive the dossier from the grant, which stopped being enough once one link could open several: unchecked, a client-scoped grant would sign a PUT into any dossier's tenant prefix. Do not "simplify" it back.

Client-scoped links have their own shorter lifetime (`portal.client_grant_ttl_days`, capped by `portal.client_grant_max_ttl_days`) because a leaked one exposes the client's whole book rather than one case. See `backlog/decisions/decision-12`.

## Portal magic-link resolution is timeboxed; the session middleware is not
ResolveClientAccessGrantFromTokenAction wraps its lookup in Timebox (200ms, matching Laravel's SessionGuard). Every portal denial is already concealed as a 404, so response time was the last channel separating an unknown token from a revoked/expired one — the empty-token branch returns before any query, and a hit costs a row read a miss does not.

Do NOT add Timebox to ResolveClientPortalGrant middleware. It resolves from SESSION_GRANT_ID, which is only ever written by ClientPortalSessionController after a successful token exchange. A session value behind a signed cookie is not attacker-iterable, so there is no oracle — timeboxing it would only add latency to every portal navigation.

Timebox::usleep() delegates to Sleep::usleep(), so Sleep::fake() + Sleep::assertSleptTimes() test it without real delay.

## Portal sessions slide; only the ceiling is absolute
Two deadlines, both owned by `PortalSessionWindow` and both clamped by the grant's `expires_at`. `portal.session_idle_minutes` is rewritten by `ResolveClientPortalGrant` on **every** request that resolves a live grant; `portal.session_absolute_minutes` is stamped once in `ClientPortalSessionController` and never touched again.

Do not "simplify" this back to one deadline. A single absolute window is what the sliding window replaced: at 15 minutes it 404'd clients partway through a ten-item questionnaire on a phone, which is the worst failure the product has because the user is an outsider with no account and no support channel.

The idle window must stay below `session.lifetime` — the Laravel session expires on idleness too, so a larger portal window would promise something the session store drops first. `ProductionConfigurationValidator` fails boot on that rather than letting it ship.

`POST portal/session/extend` has an empty controller on purpose: passing the middleware is what extends the session. It exists so the pre-expiry banner has a deliberate user action to call — a background heartbeat would defeat idle expiry entirely. It cannot become an unbounded keep-alive because the ceiling never moves.

## Session end is the one portal denial that is disclosed, not 404'd
`ResolveClientPortalGrant` redirects to `portal.ended` for exactly two cases — the window elapsed (`PortalSessionEndReason::TimedOut`) and the grant is revoked or expired (`AccessEnded`). Everything else stays a bare 404: no portal session at all, a `grant_id` that resolves to no row, a missing tenant.

This is safe for the same reason the middleware is not timeboxed: `SESSION_GRANT_ID` is only ever written by `ClientPortalSessionController` after a successful token exchange, and it sits behind a signed cookie, so reaching either branch **proves** the visitor already held a legitimate session. There is no oracle for a prober. Do not "restore consistency" by turning the redirect back into `abort(404)` — the app-wide error page offers a guest a "Go to login" button, which is the exact confusion this replaced.

`SESSION_FIRM_NAME` deliberately survives teardown so the ended page stays branded. It is the tenant's public display name, already in the email that brought the client here.

## Client downloads share the staff hardening, not the staff status code
`ClientPortalUploadController::download` goes through `Dossiers\Support\UploadedDocumentResponse` (S3 signed-URL redirect or local stream, plus `UNTRUSTED_FILE_HEADERS`) and `Dossiers\Actions\GuardUploadedDocumentDeliveryAction` (malware verdict, fail-closed on unscanned, audited refusal). Both are shared with `UploadedDocumentController` — do not fork either.

What differs is the refusal: staff get 403 with a message, the portal gets **404**. That is why the guard returns a bool instead of aborting itself.

This route carries no `RequirePassword`, breaking the `routes.md` rule that every byte-streaming route does. That middleware presumes an account; a portal client has none. The gate is the hashed grant, the short session, and the scan verdict, and it is declared in `AUTHORIZATION_NOT_VIA_GATE`.

