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

**Known tradeoff:** a grant token can be re-exchanged until it expires (operator
choice, up to 90 days) rather than being single-use. Clients lose emails and
re-open links from multiple devices, and single-use tokens generated a support
burden that outweighed the benefit. There is currently **no device or IP binding**
and **no notification when a link is used from a new device** — both are worth
adding before scaling the client base.

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
(`SECURITY_CSP_ENABLED`, `SECURITY_CSP_REPORT_ONLY`, the HSTS keys). Caddy also sets
`nosniff` and `Referrer-Policy` at the edge in `docker/production/Caddyfile`.

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
`FortifyServiceProvider`: login (per email + IP), two-factor challenge (5/min), and
passkeys. Sessions are Redis-backed, encrypted, and `secure` in production.
Invitation tokens follow the same hashed-and-expiring pattern as portal grants.

**Known gap:** 2FA is available but not *enforced*. For a platform holding client
financial records, requiring it for every staff member — or at minimum for roles with
the document download permission — is the obvious next step.

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

**Known gap:** this covers scheduled deletion, not a GDPR **data-subject request**.
There is no endpoint to export or erase everything held about one individual on
demand. That is required, not optional, once real client data is in the system.

## Production configuration guard

`App\Platform\Services\Production\ProductionConfigurationValidator` **fails boot** in
production if any of these is wrong: `app.debug` on, non-HTTPS `APP_URL`, cache or
queue not Redis, Redis not on TLS, sessions not Redis/encrypted/secure/domain-scoped,
filesystem not S3 (with throw + report on), broadcasting not Reverb, OCR driver not
Textract, no delivering mailer, or unset trusted proxies. Misconfiguration cannot
reach traffic.

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
4. **2FA not enforced** (see above).
5. **No GDPR data-subject export/erasure flow** (see above).
6. **No SAST or container scanning** (see above).
7. **Portal links are re-exchangeable, unbound to device or IP** (see above).
8. **Frontend has no meaningful test coverage** and there is no end-to-end test of
   the client portal flow — the one path an unauthenticated user touches.

## Reporting a vulnerability

Email the maintainer rather than opening a public issue.
