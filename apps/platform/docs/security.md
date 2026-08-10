# Security

How this platform protects the documents firms and their clients put into it, and
where the known gaps still are. Read alongside [architecture](architecture.md) and
[permissions](permissions.md).

## What we are protecting

Accounting and advisory firms upload and collect **client financial records** —
payslips, tax filings, bank statements, identity documents. The threat model that
follows from that:

| Threat | Primary control |
|---|---|
| One firm reading another firm's data | `TenantScope` + strict mode, enforced per request |
| A staff member reading data they have no role for | Spatie permissions behind per-model policies |
| A leaked magic link granting standing access | Hashed, expiring grants exchanged for short server-side sessions |
| A stolen session reading documents | Step-up password confirmation on every document route |
| A malicious upload attacking a reviewer's browser | Script-blocked, `nosniff` file responses served off S3 |
| Data outliving its lawful basis | Scheduled retention purge including stored files |
| Silent access | Typed audit events on view, download, export, and access denial |

## Tenant isolation

Every tenant-owned record extends `TenantOwnedModel`, which applies
`App\Tenancy\Scopes\TenantScope` as a global scope.

- **Soft by default.** With no current tenant, the scope appends `where 0 = 1`. An
  unscoped query returns nothing rather than everything — a bug becomes an empty
  page, never a cross-tenant leak.
- **Strict on request paths.** Workspace and portal requests call
  `TenantContext::enableStrict()`. In strict mode the same situation throws a
  `RuntimeException` instead, so the mistake surfaces in Sentry rather than hiding.
- **Octane-safe.** The strict flag lives in Laravel's `Context`, not a static
  property, so it cannot survive into the next request on a reused worker.
- **Ordering is load-bearing.** `bootstrap/app.php` prepends the tenant resolvers
  ahead of `SubstituteBindings`, so the tenant is current *before* route model
  binding runs. `tests/Feature/Tenancy/TenantMiddlewarePriorityTest.php` guards this.

Deliberate bypasses go through `TenantOwnedModel::queryWithoutTenantScope()`, which
is greppable. Today it is used only where no tenant can be current yet: resolving a
portal magic link, and retention pruning.

## Authorization

Permissions are string-backed enums implementing `TenantPermission`, aggregated by
`PermissionRegistry` (`config/tenant-permissions.php`), synced into Spatie roles, and
enforced in policies. Owners implicitly hold every registered permission; no other
role is implicit. The registry is also generated into TypeScript so the UI cannot
drift from the backend. See [permissions](permissions.md).

Document routes additionally require **recent password confirmation**
(`auth.document_access_timeout`, 15 minutes by default). A hijacked session cannot
pull documents without the password.

## Client portal

Clients never hold an account. Access is a `ClientAccessGrant` scoped to one dossier.

- Tokens are `Str::random(64)`, stored **SHA-256 hashed**, and marked `$hidden`.
  The plaintext exists only in the outgoing email.
- Visiting the link exchanges the token for a **server-side session** capped at
  `portal.session_lifetime_minutes` (15) and never beyond the grant's own expiry.
  The session is regenerated with `destroy: true` on exchange.
- After exchange the controller forces `X-Requested-With`, which stops Laravel's
  `StartSession` from copying the redeemed bearer URL into `_previous.url`.
- Every portal response gets `no-store`, `no-referrer`, and
  `X-Robots-Tag: noindex, nofollow, noarchive` via `HardenClientPortalResponse`.
  Inertia history encryption is on.
- Grants are revocable, prunable, and record `last_used_at` under a row lock.
- Rate limits: 30 req/min on the portal, 10 req/min on token exchange.
- **Per-dossier portal upload quota:** cumulative uploaded bytes across all
  document requests on the dossier are capped at 500 MB
  (`DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER`). Exceeding it returns a
  clear validation error on `documents` rather than accepting more files.

**Known tradeoff:** a grant token can be re-exchanged until it expires (operator
choice, up to 90 days) rather than being single-use. Clients lose emails and
re-open links from multiple devices, and single-use tokens generated a support
burden that outweighed the benefit.

### Redemption from a new device

That tradeoff stands, but a re-exchangeable link is now *watched*. On redemption
`RegisterClientPortalDeviceAction` compares a coarse fingerprint against the one
that redeemed the link first. `portal.new_device_action` decides what follows:

| Mode | Behaviour |
|---|---|
| `notify` (default) | Client is let in; the responsible staff member gets an inbox item, and `ClientPortalAccessedFromNewDevice` lands in the trail |
| `block` | Refused as a **404**, so a probe cannot distinguish a blocked device from a token that never existed. Audited as `ClientPortalNewDeviceBlocked` |
| `ignore` | Nothing recorded |

Design points that are load-bearing:

- **The fingerprint is deliberately coarse** — `sha256(user-agent with version
  numbers stripped | IPv4 /24 or IPv6 /64)`. A precise fingerprint would fire on
  every browser auto-update and every DHCP lease change, and the alert would be
  trained away. Only the hash is stored: this is a signal about a credential, not
  a record of where a client lives.
- **At most one alert per grant** (`new_device_seen_at` doubles as the flag). The
  finding worth acting on is "this link is open in more than one place"; repeating
  it each time a client switches phone and laptop would bury it.
- **`notify`, not `block`, is the default.** Blocking strands clients who move from
  mobile data to wifi — the same support burden that killed single-use tokens.
- **It fails open-but-loud:** an unrecognised policy value falls back to `notify`.
- Checked at **redemption only**, not per request: once a session exists the
  device cannot meaningfully change.

**Still open:** the fingerprint is a detection signal, not a binding — a leaked
link used from a matching network and browser family is indistinguishable from the
real client.

## Uploads and document delivery

Accepted types are `pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx` at 20 MB,
validated by Laravel's `File` rule (which checks the real MIME type, not just the
extension). HTML, SVG, and scripts are not accepted.

Delivery is deliberately two-path:

- **Production (S3):** `download`/`preview` authorize, audit, then 302 to a
  **5-minute presigned URL** on the storage host. The bytes never leave S3 through
  this origin, so a malicious file lands on a different origin from the app.
- **Local/CI (local disk):** the file streams through Laravel so `Storage::fake()`
  works in tests.

Both paths send `UploadedDocumentController::UNTRUSTED_FILE_HEADERS`:
`X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
`Cross-Origin-Resource-Policy: same-origin`, `X-Robots-Tag: noindex`, and a
per-file CSP of
`script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`.

That policy blocks scripts rather than using `default-src 'none'` or a bare
`sandbox`: both interfere with the browser's built-in PDF viewer, and PDF preview is
the main reason the endpoint exists. `script-src 'none'` still stops JavaScript in a
smuggled HTML/SVG file *and* JavaScript embedded inside a PDF, which is the real
threat.

At rest, the Pulumi stack gives the documents bucket a **customer-managed KMS key
with rotation**, SSE-KMS by default, full public-access block, versioning,
noncurrent-version expiry, and a bucket policy denying non-TLS transport.

## Response hardening

`App\Shared\Http\Middleware\AddSecurityHeaders` runs on every web route and sets:

| Header | Value |
|---|---|
| `Content-Security-Policy` | assembled per request — see below |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (HTTPS requests only) |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | camera, mic, geolocation, USB, payment, … all denied |

Headers are only written when **absent**, so a route may harden further first — the
portal's `no-referrer` survives this middleware. Tuning lives in `config/security.php`
(`SECURITY_CSP_ENABLED`, `SECURITY_CSP_REPORT_ONLY`, the HSTS keys).

**Referrer-Policy ownership:** the application owns this header exclusively
(`AddSecurityHeaders` for the baseline, `HardenClientPortalResponse` for
`no-referrer` on portal routes). The production Caddyfile must not set
`Referrer-Policy` — Caddy's bare `header` directive *replaces* the field and would
downgrade portal responses back to `strict-origin-when-cross-origin`. Caddy may
still set `X-Content-Type-Options: nosniff` (idempotent) and strip `Server`.
A regression test in `tests/Feature/Security/ProductionCaddyfileTest.php` guards
the Caddyfile.

### The Content Security Policy

`App\Shared\Http\ContentSecurityPolicy` builds the policy per request, deriving
everything environment-specific from existing config rather than restating it: the
Vite nonce, the Vite dev server when running hot, the Reverb websocket from
`broadcasting.connections.reverb`, and the S3 host from `filesystems.disks.s3`.

Scripts are **nonce-gated**. `AddSecurityHeaders` calls `Vite::useCspNonce()` on the
way in so the root Blade view, `@vite`, and `@fonts` all stamp the same nonce; the
policy is written on the way out. A regression test asserts that every executable
`<script>` and `<style>` in the rendered document carries it.

Two deliberate relaxations:

- **`style-src` allows `'unsafe-inline'`.** Tailwind v4 injects `<style>` elements at
  runtime in dev, and React sets inline `style` attributes throughout (dnd-kit
  transforms, React Aria positioning). Inline styles are a far weaker vector than
  inline scripts, which stay nonce-gated.
- **`script-src` is `'self'` + nonce, not `'strict-dynamic'`.** Every bundle is
  same-origin under `/build`, and uploads never land on this origin, so `'self'`
  costs little here while keeping Vite's dev module graph working unmodified.

Inertia's page payload is a `<script type="application/json">` data block. The HTML
spec never executes those and therefore never CSP-checks them, so it carries no nonce
by design.

**When adding a directive, remember the dev server.** In hot mode Vite serves more than
JavaScript from `localhost:5173` — `laravel-vite-plugin` also serves the `.woff2` files
through its own font middleware, which is why `font-src` needs the dev origin and not
just `script-src`. `applyDevelopmentServerOrigins()` fans that origin across every fetch
directive (`script-src`, `style-src`, `img-src`, `font-src`, `connect-src`, `media-src`,
`worker-src`), with the `ws://` form added to `connect-src` alone. New fetch directives
belong in that list. None of this applies in production, where `isRunningHot()` is false
and fonts are self-hosted under `public/build/assets/`.

Two test-isolation guards keep this honest: `tests/TestCase::setUp()` pins Vite to
built-manifest mode so a running `npm run dev` cannot change rendered output, and
`phpunit.xml` pins `SECURITY_CSP_ENABLED`/`SECURITY_CSP_REPORT_ONLY` so a developer's
local overrides cannot mask a broken policy.

To roll out a tightened policy safely, set `SECURITY_CSP_REPORT_ONLY=true` (and
optionally `SECURITY_CSP_REPORT_URI`), watch reports, then flip it back.

## Authentication

Laravel Fortify with 2FA (TOTP + recovery codes) and passkeys. Rate limiters in
`FortifyServiceProvider`: login (5/min per email + IP), two-factor challenge (5/min),
passkeys (10/min), password reset request (3/min per email, 10/min per IP), and
password reset submission (10/min per IP). Fortify itself only wires the first three
through `config/fortify.php`'s `limiters` array — it has no config hook for the
password reset routes, so those two are throttled by attaching `throttle:` middleware
directly to the named routes once Fortify has registered them. Sessions are
Redis-backed, encrypted, and `secure` in production. Invitation tokens follow the
same hashed-and-expiring pattern as portal grants.

### Two-factor enforcement

2FA is **required**, not merely available. `config/security.php` (`two_factor`) picks
the policy and `App\Accounts\Http\Middleware\RequireTwoFactorAuthentication` applies it:

| Mode | Who must enrol |
|---|---|
| `off` | Nobody. Rejected at boot in production. |
| `all` (default) | Every staff account. |
| `by_permission` | Staff whose role in the current workspace carries `security.two_factor.permission` — `documents.download` by default. |

`grace_days` (14 by default) is counted from account creation so a new hire can work
while they enrol; 0 requires enrolment before the first page load.

Three details that are load-bearing:

- **Only a confirmed TOTP factor counts.** A registered passkey does not: passkeys are
  an alternative login here, so a user holding one can still sign in with a password
  alone and would have no second factor on that path. Fortify's `confirm` option is
  on, so an abandoned enrolment (secret, no `two_factor_confirmed_at`) does not count
  either.
- **Enforcement is applied per route group, not globally.** `routes/web.php` and
  `routes/tenant.php` carry the middleware; `routes/settings.php` and the Fortify auth
  routes deliberately do not, because the enrolment page, password confirmation, and
  logout all have to stay reachable for someone who has not enrolled yet. An
  exemption list keyed on Fortify's route names would have rotted into a lockout the
  first time the package renamed one.
- **It fails closed.** An unrecognised mode is treated as `all`, so a typo in the
  deploy environment cannot silently switch enforcement off.

XHR callers get a 403 rather than a redirect, so a poll cannot render the settings
page into a JSON slot.

**Known gap:** because factory users are created "now", the 14-day grace window means
the rest of the test suite never exercises enforcement — only
`tests/Feature/Auth/TwoFactorEnforcementTest.php` does. Treat that file as the sole
guard on this behaviour.

## Audit and retention

`App\Audit\Enums\AuditEvent` defines 40+ typed events, including `DocumentViewed`,
`DocumentDownloaded`, `DossierExportDownloaded`, `ClientPortalAccessed`, and
`AccessDenied`. Activity is tenant-scoped and readable in the workspace UI.

`config/retention.php` drives scheduled purging:

| Data | Default |
|---|---|
| Archived clients and dossiers (with stored files and activity) | 1095 days |
| Activity log | 1095 days |
| Portal access grants | 90 days |
| Member invitations | 90 days |
| Database notifications | 90 days |
| Failed queue jobs | 168 hours |

### Data-subject requests

Retention above is *scheduled* deletion. A client exercising their rights on
demand is a separate, two-step workflow, because the regulation allows a month to
answer and erasure cannot be undone: a request is **logged** first
(`DataSubjectRequest`, with a due date from `retention.data_subject_response_days`)
and **fulfilled or refused** second. Refusal requires a recorded reason — "we are
legally obliged to keep this" is a valid answer, but it has to be defensible later.

**Export** (Art. 15/20) builds a ZIP containing `data.json` — client, dossiers,
document requests and answers, uploads, audio and transcripts, portal access
history, activity trail — plus the original files. It runs as a queued job and the
artefact expires after `retention.data_subject_export_hours` (72), because a copy
of everything about one person must not sit on disk indefinitely. Portal grant
tokens are deliberately excluded: a token is access, not data.

**Erasure** (Art. 17) is *anonymisation*, and the UI says so to the operator:

| Removed | Kept |
|---|---|
| Client name → `Erased client #<id>` | Dossiers |
| Client email → `erased-<id>@erased.invalid` (RFC 2606, unroutable) | Uploaded documents |
| Portal grants and their device history | Audio and transcripts |
| Any export archive already produced | The erasure audit entry |
| Activity trail for the client and its dossiers | |

Three things that make this correct rather than merely plausible:

- **`Client` logs its fillable attributes.** Anonymising without `disableLogging()`
  writes the real name and email into the new activity row's `attribute_changes` —
  preserving exactly what the erasure removes. A test asserts the old values
  appear nowhere in the trail.
- **Activity rows are immutable** (`Activity::booted()` throws on update), so the
  trail is *deleted* via `PurgeSubjectActivityLogAction`, not redacted in place.
  The firm loses the access trail for those dossiers; the erasure entry recorded
  against the *request* survives, with counts of what it covered.
- **The retained records may still identify the person.** A payslip is a payslip.
  Art. 17(3)(b) permits the retention, but the answer to the client has to say so.

Access is gated on `DataSubjectPermission` (`data_subject.view` / `.manage`), which
is in **no role's defaults** — only owners hold it until a firm grants it
deliberately. The archive download additionally requires recent password
confirmation, like every document route.

## Production configuration guard

`App\Platform\Services\Production\ProductionConfigurationValidator` **fails boot** in
production if any of these is wrong: `app.debug` on, non-HTTPS `APP_URL`, cache or
queue not Redis, Redis not on TLS, sessions not Redis/encrypted/secure/domain-scoped,
filesystem not S3 (with throw + report on), broadcasting not Reverb, OCR driver not
Textract, no delivering mailer, or trusted proxies unset / set to `REMOTE_ADDR` /
`*` (must be LB/VPC CIDRs or `PRIVATE_SUBNETS`). Misconfiguration cannot reach
traffic.

## Supply chain

CI (`.github/workflows/platform.yml`) runs `composer audit` and
`npm audit --audit-level=high` in the quality gate, holds an 87% backend coverage
floor, tests PostgreSQL migrations and one rollback, and publishes an immutable OCI
image with an SBOM and max provenance. Dependabot watches Composer, npm, and Actions
weekly.

**Known gap:** no SAST (CodeQL) and no container image scanning (Trivy/Grype) — the
SBOM is generated but nothing consumes it.

## Open gaps

Tracked honestly, roughly in priority order:

1. **No malware scanning on uploads.** Clients upload arbitrary files that staff then
   open. GuardDuty Malware Protection for S3 (bucket-level) or ClamAV in
   `ProcessUploadedDocumentJob`, gating download/preview on a `scan_status`.
2. **No application compute in the Pulumi stack.** `infra/` provisions S3, SNS, SQS,
   and KMS only; there is no ECS service, queue worker, scheduler, or app deploy
   workflow. CI builds the image but nothing runs it.
3. **Static IAM credentials for local development** — narrowed, not yet eliminated.
   The permission set now lives in one shared document (`infra/src/policies.ts`) used
   by both the local IAM user and an **ECS task role** that is already provisioned
   (`infra/src/application.ts`), so the deployed app will carry no keys at all. That
   switch needs no application code changes — both credential paths skip explicit
   credentials when `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` are empty — but it
   cannot happen until gap 2 is closed. CI already authenticates via GitHub OIDC with
   no stored keys; see [`infra/bootstrap/`](../infra/bootstrap/README.md).
4. **No SAST or container scanning** (see above).
5. **Portal links are re-exchangeable and only *watched*, not bound** — redemption
   from an unfamiliar device is detected and alerted (see above), but a leaked link
   used from a matching network and browser family still passes.
6. **Frontend has no meaningful test coverage** and there is no end-to-end test of
   the client portal flow — the one path an unauthenticated user touches.
7. **An expired platform support session is not always cleared or audited.**
   `EnsureValidTenantMembership` calls `clearIfExpired()`, but
   `InitializeTenantFromRoute` returns 404 on `canAccessTenant()` first, so for a
   support user with no membership in the firm the expiry never fires —
   `AuditEvent::SupportSessionExpired` is only reachable when the support user is
   also a member. The trail then shows a session starting and never ending.

## Reporting a vulnerability

Email the maintainer rather than opening a public issue.
