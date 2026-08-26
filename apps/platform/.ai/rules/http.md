---
paths:
  - 'app/Shared/Http/**'
---

# Http

## CSP is nonce-gated and derived from config — mind the Vite dev server
`ContentSecurityPolicy` builds the policy per request, deriving everything environment-specific from existing config: the Vite nonce, the dev server when hot, the Reverb websocket from `broadcasting.connections.reverb`, the S3 host from `filesystems.disks.s3`. Do not restate hosts literally.

`AddSecurityHeaders` calls `Vite::useCspNonce()` on the way *in* (so the root Blade view, `@vite`, and `@fonts` stamp the same nonce) and writes the policy on the way *out*. It is appended first in the web stack for that reason — first in, last out.

Headers are only written when **absent**, so a route may harden further first. That is what preserves the portal's `no-referrer` over the baseline `strict-origin-when-cross-origin`.

Adding a fetch directive? Add it to `applyDevelopmentServerOrigins()` too. In hot mode Vite serves `.woff2` from `localhost:5173` via its own font middleware, which is why `font-src` needs the dev origin — not just `script-src`.

Two deliberate relaxations, do not "fix" them: `style-src` allows `'unsafe-inline'` (Tailwind v4 runtime injection + React inline style attributes), and `script-src` is `'self'` + nonce rather than `'strict-dynamic'`. Inertia's page payload is `<script type="application/json">` and carries no nonce by design — the HTML spec never executes or CSP-checks those.

Dashboard CSP extras stay path-scoped: Horizon and Telescope both get `'unsafe-eval'` + `fonts.bunny.net` when `AddSecurityHeaders` detects `config('horizon.path')` or `config('telescope.path')`. Stamp each dashboard's Blade nonce via `AddHorizonCspNonce` / `AddTelescopeCspNonce`. Never apply those extras globally — the main app must keep failing SecurityHeadersTest if they leak onto `/login`.

`SECURITY_CSP_REPORT_ONLY=true` only switches to `Content-Security-Policy-Report-Only` when `SECURITY_CSP_REPORT_URI` is also set. Report-Only without a sink is ignored by browsers (console warning, no enforcement); `headerName()` falls back to the enforcing header in that case.

The application owns `Referrer-Policy` exclusively. `docker/8.5/Caddyfile` (the only remaining Caddyfile after deployed images were removed) must not set it — Caddy's bare `header` directive replaces the field and would downgrade portal responses. Guarded by `tests/Feature/Security/ProductionCaddyfileTest.php`.

## CSP report-only needs a report_uri
SECURITY_CSP_REPORT_ONLY only switches to Content-Security-Policy-Report-Only when SECURITY_CSP_REPORT_URI is also set. Report-Only without a sink is ignored by browsers (console warning, no enforcement). ContentSecurityPolicy::headerName() falls back to the enforcing header in that case — do not "fix" that by sending bare Report-Only.
