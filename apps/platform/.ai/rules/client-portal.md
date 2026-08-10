---
paths:
  - 'app/ClientPortal/**'
---

# Client Portal

## Portal grants: hashed tokens exchanged for short server-side sessions
Clients never hold an account. Access is a `ClientAccessGrant` scoped to one dossier: `Str::random(64)` stored **SHA-256 hashed** and marked `$hidden` — the plaintext exists only in the outgoing email.

Visiting the link exchanges the token for a server-side session capped at `portal.session_lifetime_minutes` and never beyond the grant's own expiry, with `regenerate(destroy: true)`. After exchange the controller forces `X-Requested-With` — this stops `StartSession` copying the redeemed bearer URL into `_previous.url`. Do not remove it.

`HardenClientPortalResponse` puts `no-store`, `no-referrer`, and `X-Robots-Tag: noindex, nofollow, noarchive` on every portal response, and is re-applied in the `bootstrap/app.php` exception responder so error pages stay hardened. Inertia history encryption is on.

Grants are revocable, `MassPrunable`, and record `last_used_at` under a row lock.

Redemption is device-checked once, in `ClientPortalSessionController` (not per request — the device cannot change mid-session). `RegisterClientPortalDeviceAction` compares a **coarse** fingerprint (`PortalDeviceFingerprint`: user agent with version numbers stripped + IPv4 /24 or IPv6 /64, hashed). Coarse on purpose: a precise fingerprint fires on every browser update and DHCP lease and the alert gets trained away. Only the hash is persisted. `portal.new_device_action` is `notify` (default) / `block` / `ignore`; `block` aborts **404**, never 403. One alert per grant — `new_device_seen_at` is the flag.

Portal submissions delegate to `DocumentRequestTransitions::canSubmit()` — clients may submit only when an item is `pending` or `rejected` and the dossier is not completed. Never duplicate that rule in an HTTP adapter or the UI.

The portal conceals cross-dossier probes as 404s via `abort()`, which deliberately does **not** reach `LogAccessDeniedAction` (only policy denials do). Keep it that way — otherwise probes flood the audit trail.

Cumulative portal uploads per dossier are capped by `DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER`.
