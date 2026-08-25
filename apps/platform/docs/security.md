# Security

How this platform protects the documents firms and their clients put into it, which
risks are accepted for v1.0, and what work remains tracked. Read alongside
[architecture](architecture.md) and [permissions](permissions.md).

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
- **Writes refuse a foreign `tenant_id`.** `BelongsToTenant` stamps the current tenant
  when the attribute is absent, and throws if a create supplies a different id — so a
  future `->create($request->all())` cannot quietly park a row in another firm.
  `tenant_id` is also immutable after create. Factories still mass-assign it when it
  matches the current tenant (or when building under that tenant's context).

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
- Visiting the link exchanges the token for a **server-side session** bounded by
  two deadlines, both clamped by the grant's own expiry and both owned by
  `PortalSessionWindow`. `portal.session_idle_minutes` (15) is renewed on every
  portal request; `portal.session_absolute_minutes` (240) is stamped once at
  redemption and never extended, so an abandoned or stolen session still dies on
  schedule. The session is regenerated with `destroy: true` on exchange.
  The idle window must stay below `session.lifetime` — the Laravel session
  expires on idleness too, and `ProductionConfigurationValidator` fails boot
  rather than promise a window the session store would drop first.
- A session that ends redirects to `portal/ended`, which distinguishes a timeout
  ("your work is saved, re-open the emailed link") from withdrawn access. This is
  the **one** deliberate exception to concealing portal denials as 404s, and it
  is safe for the same reason the session resolver is not timeboxed:
  `portal.grant_id` is written only after a successful token exchange and sits
  behind a signed cookie, so reaching that branch proves the visitor already held
  a legitimate session. Everything else — no session, an unresolvable grant id, a
  missing tenant — stays a bare 404.
- After exchange the controller forces `X-Requested-With`, which stops Laravel's
  `StartSession` from copying the redeemed bearer URL into `_previous.url`.
- Every portal response gets `no-store`, `no-referrer`, and
  `X-Robots-Tag: noindex, nofollow, noarchive` via `HardenClientPortalResponse`.
  Inertia history encryption is on.
- **The token is stripped from error reports on both sides.** It travels in the URL
  path, and Sentry records request URLs regardless of `send_default_pii`, so an
  exception on a portal route would otherwise ship a live credential to a third
  party. `RedactsPortalTokensFromEvents` (PHP) and `resources/js/lib/observability.ts`
  (browser) rewrite it in the request URL, the transaction name, and breadcrumbs.
  Both patterns track the `[A-Za-z0-9]+` route constraint in `routes/portal.php`;
  loosening that constraint means updating all three.
- Grants are revocable, prunable, and record `last_used_at` under a row lock.
- **Clients can download their own uploads** (`portal.document-requests.uploaded-documents.download`),
  through the same `UploadedDocumentResponse` hardening and the same
  `GuardUploadedDocumentDeliveryAction` malware verdict as staff downloads —
  refused as a 404 rather than staff's 403. This route deliberately carries no
  `RequirePassword`: a portal client has no account to re-authenticate with, so
  the gate is the hashed grant, the short session, and the scan verdict.
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

### Malware scanning

GuardDuty Malware Protection scans every object as it lands in the documents
bucket and writes its verdict back onto the object as a
`GuardDutyMalwareScanStatus` tag. `download` and `preview` read that tag before
serving anything — `App\Dossiers\Actions\ResolveMalwareScanStatusAction` — and
refuse everything that is not explicitly `NO_THREATS_FOUND`. Audio recordings go
through the same gate: they are client-uploaded bytes in the same bucket.

The gate **fails closed on four cases that are not detections**, and that is the
part worth understanding:

| Verdict | Served? | Why |
|---|---|---|
| `NO_THREATS_FOUND` | yes | the only pass |
| `THREATS_FOUND` | no | malware |
| `UNSUPPORTED` | no | GuardDuty cannot scan this type |
| `ACCESS_DENIED` | no | GuardDuty could not read it — usually a missing KMS grant |
| `FAILED` | no | the scan errored |
| *no tag at all* | no | the scan has not run yet |

Scanning is asynchronous, so a document uploaded seconds ago genuinely has no tag,
and a staff member opening it immediately gets a 403. That is the intended
behaviour: at the point of delivery, "not yet scanned" carries exactly as much
assurance as "infected". An S3 error is treated the same way — a gate that opens
when its dependency is unreachable is not a gate.

Every refusal writes a `file.blocked_by_malware_scan` activity row carrying the
verdict (null when there is none yet), so an operator can tell a detection apart
from a scan that has not caught up. Findings also fan out to EventBridge → SNS.
There is deliberately **no automatic quarantine**: the object is already inert
once tagged, since nothing will serve it, and moving or deleting it would destroy
the evidence.

The verdict is read live rather than mirrored into a `scan_status` column. A
column needs a queue, a consumer and a service to keep it fed, and can still be
wrong when that consumer lags; the tag is the truth, one API call away, at the
only moment the answer matters. What it gives up is a "scanning…" state in the UI.

`SECURITY_MALWARE_SCANNING_ENABLED` gates the whole check and defaults to off,
because a bucket with no GuardDuty attached never tags anything and every download
would be refused. `ProductionConfigurationValidator` rejects it being off in
production and staging, so the local default cannot ship.

### Who read what

CloudTrail data events cover object reads and writes on the documents bucket,
delivered to a separate log bucket with file-validation digests and a 365-day
lifecycle. This is not a duplicate of the activity log: the activity log records
that a staff member clicked download, CloudTrail records that S3 served the bytes.
They diverge exactly where it matters — a presigned URL forwarded to someone else,
or credentials used outside the application, appear in CloudTrail and nowhere
else.

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
`no-referrer` on portal routes). `docker/8.5/Caddyfile` must not set
`Referrer-Policy` — Caddy's bare `header` directive *replaces* the field and would
downgrade portal responses back to `strict-origin-when-cross-origin`.
A regression test in `tests/Feature/Security/ProductionCaddyfileTest.php` guards
the Caddyfile.

### The Content Security Policy

`App\Shared\Http\ContentSecurityPolicy` builds the policy per request, deriving
everything environment-specific from existing config rather than restating it: the
Vite nonce, the Vite dev server when running hot, the Reverb websocket from
`broadcasting.connections.reverb`, the S3 host from `filesystems.disks.s3`, and the
Sentry ingest host from `services.sentry.browser_dsn`. Only the DSN's origin reaches
the header — `originFromUrl()` drops the public key along with the rest of the
userinfo. With no browser DSN configured the origin is omitted entirely.

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
`phpunit.xml` pins `SECURITY_CSP_ENABLED`/`SECURITY_CSP_REPORT_ONLY` (and blanks
`VITE_SENTRY_DSN`) so a developer's local overrides cannot mask a broken policy.

To roll out a tightened policy safely, set `SECURITY_CSP_REPORT_ONLY=true` **and**
`SECURITY_CSP_REPORT_URI` to a reporting endpoint, watch reports, then flip it back.
Report-Only without a sink is a browser no-op (console warning only); the app falls
back to the enforcing `Content-Security-Policy` header in that case.

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

Anonymous `GET /ready` stays unauthenticated so load balancers can probe it, but it
is throttled at **60 requests/minute per IP** — comfortably above typical ALB poll
intervals (5–30s). Failures return only `{"status":"unavailable"}` (details go to
Sentry). Full diagnostics live at `/health`, which requires an authenticated platform
support session.

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

**Personal data lives in the audit trail, not the application log.** A client
filename is personal data on its own — `Loonstrook_J_de_Vries_maart.pdf` names the
person, the document type and the period without anyone opening the file. The audit
trail may carry it, because that trail is tenant-scoped and is deleted by both
`retention:purge-legal` and `AnonymizeDataSubjectAction`. The application log stack
is neither: it has its own retention and access, and nothing purges it, so anything
written there cannot be erased on request. OCR, transcription and their queue jobs
therefore log `id` + `tenant_id` only. Enforced two ways — `tests/Unit/DocumentFilenameLoggingTest.php`
scans every `Log::` context array in `app/`, and a full OCR run is asserted to leak
no filename in `tests/Feature/DocumentProcessing/ProcessUploadedDocumentJobTest.php`.

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
Textract, no delivering mailer, either Sentry DSN empty (the PHP one and the browser
one — a React render error produces no PHP exception and no log line, so unreported
its only symptom is a user saying the screen went blank), or trusted proxies unset /
set to `REMOTE_ADDR` / `*` (must be LB/VPC CIDRs or `PRIVATE_SUBNETS`).
Misconfiguration cannot reach traffic.

## Supply chain

CI ([`platform.yml`](../../.github/workflows/platform.yml)) runs `composer audit` and
`npm audit --audit-level=high` in the quality gate, holds an 87% backend coverage
floor, tests PostgreSQL migrations and one rollback, and builds an immutable OCI
image with an SBOM and max provenance. That image is pushed to **no registry** — it
lands as a GitHub Actions artifact with 7-day retention. Dependabot watches
Composer, npm, and Actions weekly.

**SAST:** CodeQL is not enabled (needs a public repository or GitHub Advanced
Security). PHPStan in `ci:quality` is type analysis, not security SAST.

**Container scanning:** after the OCI tarball is built, Trivy scans it in place
(`input:` — no registry). The job **fails** on HIGH or CRITICAL findings that have a
published fix (`severity: HIGH,CRITICAL`, `ignore-unfixed: true`, `exit-code: 1`).

## Accepted risks (v1.0)

These are deliberate deferrals with a recorded decision — not unfinished backlog.
Unless noted otherwise, the **reopen trigger** is the same: before the first real
client document enters the system.

| Risk | Why it is acceptable now | Reopen | Decision |
|---|---|---|---|
| ~~**No malware scanning on uploads**~~ | **Closed by TASK-34.1.** GuardDuty Malware Protection scans every upload and tags it; download and preview refuse anything not tagged clean. See [Malware scanning](#malware-scanning). | n/a — resolved | decision-1, superseded by decision-7 |
| ~~**No application compute in Pulumi**~~ | **Closed by TASK-34.2.** `infra/` now provisions the VPC, ALB, ECS Fargate services, RDS, Valkey, ECR, Secrets Manager and SES; [`release.yml`](../../.github/workflows/release.yml) builds, pushes, migrates and rolls. | n/a — resolved | decision-2, superseded by decision-8 |
| **Static IAM credentials for local development** | Permissions live in one shared managed policy (`infra/src/shared/policies.ts`) carried by both the local IAM user and the ECS task role (`infra/src/shared/application.ts`), so the two cannot drift. App code already skips keys when env credentials are empty; CI uses GitHub OIDC. Only the production stack mints the key (`localOperatorAccess`). | Compute now exists (decision-2 closed), so the deployed path no longer uses keys at all. Remaining scope is the developer laptop. | decision-3 |
| **Portal grants re-exchangeable and watched, not bound** | Product choice: lost emails and multi-device use made single-use tokens a support burden. New-device redemption is watched (`notify` / `block` / `ignore`); a leaked link from a matching network + browser family can still pass. Canonical rationale stays in [Known tradeoff](#client-portal) above — do not rewrite it here. | Before first real client document: reassess defaulting `block`, shorter TTL, or tighter binding for the first paying firm. | decision-4 |
| **No browser end-to-end tests** | Vitest line ratchet on the shared-trunk suite; portal behaviour covered at HTTP feature level in `tests/Feature/Portal/`. TASK-14.4 removed dead Playwright/Pest browser scaffolding rather than writing smoke tests. | Before first real client document if portal UX regressions become a release risk, or when browser CI budget is approved. | decision-5 |

Items formerly listed as gaps that are **already closed** (not accepted risks): staff
2FA is enforced; on-demand GDPR export/erasure exists under Data-subject requests above;
and expired platform support sessions are cleared/audited before tenant access denial
(including non-member support users — see decision-6, superseded by TASK-33).

## Remaining tracked work

| Item | Status |
|---|---|
| **No PHP / JS SAST in CI** | Tracked in **TASK-28.1**. CodeQL is deferred (private repo without Advanced Security). A Psalm + `psalm/plugin-laravel` attempt was deferred: beta19 conflicts with PHPUnit 13’s `sebastian/diff` ^9, and `dev-master` (which allows ^9) crashed on PHP 8.5. Revisit when a tagged Psalm release is installable, or when CodeQL becomes available. PHPStan in `ci:quality` remains type analysis, not security SAST. |

Nothing in the former open-gaps list should sit outside either an accepted-risk
decision or a tracked task.

## Reporting a vulnerability

Email the maintainer rather than opening a public issue.
