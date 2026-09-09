---
paths:
  - 'docker/**'
  - .dockerignore
  - vite.config.ts
  - vite.lint.ts
  - package.json
---

# Docker

## Local Sail only
`docker/8.5/` is the local Sail image (`sail:publish` output plus the Octane `Caddyfile`) — edit there, not in `vendor/laravel/sail/runtimes/`, which `composer install` overwrites. `docker/pgsql/` and `docker/floci/` support Sail services in `compose.yaml`.

Deployed images and bake targets were removed in TASK-43.5 — Laravel Cloud builds from git.

## `.dockerignore` is a credential boundary
`.env` / `.env.*` only cover top-level files. `env/profiles/aws.env` holds real IAM keys, is gitignored, and would have been baked into any locally built image by `COPY . .` — hence the bare `env` entry. Any new host-only secret path needs an entry here as well as in `.gitignore`.

## Queue workers on Cloud
Production/staging drain Redis via Laravel Horizon: a single custom background process `php artisan horizon` (not `queue:work`, not Managed Queues). `config/horizon.php` has two supervisors — `supervisor-interactive` over `WorkerQueues::INTERACTIVE` (`default,broadcasts`) and `supervisor-heavy` over `WorkerQueues::HEAVY` (`ocr,transcription,dossier-export`) — which between them must cover `WorkerQueues::NAMES` exactly. Adding a job on a new queue means updating `WorkerQueues::NAMES`; `tests/Feature/Platform/WorkerQueueCoverageTest.php` fails if a dedicated `onQueue()` literal is missing or Horizon's queue list drifts. Keep `textract:consume` / `transcribe:consume` as separate custom daemons.

## Sail Caddyfile must not set Referrer-Policy
The application owns Referrer-Policy exclusively. docker/8.5/Caddyfile is the only remaining Caddyfile after deployed images were removed (TASK-43.5) and must not set the header — Caddy's bare `header` directive replaces the field and would downgrade portal `no-referrer`. Guarded by tests/Feature/Security/ProductionCaddyfileTest.php. Do not reintroduce docker/production or docker/staging Caddyfiles; Laravel Cloud builds from git.

## Floci bucket CORS is required for browser PUTs
The browser PUTs to Floci at http://localhost:4566 from http://localhost, which is cross-origin. docker/floci/init/ready.d/01-create-local-bucket.sh must put-bucket-cors with PUT + content-type for http://localhost and http://127.0.0.1 (same allowlist as infra local uploadAllowedOrigins). Do not use a wildcard origin: a presigned URL is a bearer credential. The Laravel app CORS/CSP settings are never consulted for this request — a missing bucket rule is a 403 preflight. The script is bind-mounted; applying CORS to a bucket that already exists still needs a Floci restart or a one-shot aws s3api put-bucket-cors, because ready.d only runs at container start.

## React Compiler via rolldown Babel; SSR off in Vite+
Wire babel-plugin-react-compiler with @rolldown/plugin-babel + reactCompilerPreset() from @vitejs/plugin-react (v6 dropped inline babel). Keep inertia({ ssr: false }) aligned with config/inertia.php enabled=false — do not reintroduce build:ssr without an SSR entry, production SSR process, and Cloud toggle together. Wrap Laravel/Inertia/Wayfinder/Sentry in lazyPlugins so `vp check` does not load them; `vp test` must use react() only (VITEST=true) so Wayfinder does not shell out to artisan.

## Vite+ is the frontend toolchain
Use vp via npm scripts (dev/build/check/test), not standalone vite/eslint/prettier/biome. Pin vite-plus. Import boundaries live in vite.lint.ts as Oxlint no-restricted-imports; overrides last-match-wins so each list restates the pages/ ban. Keep npm run types:check (codegen then tsc) as the type gate — do not enable lint.options.typeCheck. vp test must not load Wayfinder (VITEST=true uses react() only). After any npm package change, install on host and in Sail. Platform CI uses setup-vp (see `.ai/rules/workflows.md`); do not install a global `vp` into the Sail image.

## Babel 8 is compiler-only; no concurrently
Babel is v8 (@babel/core ^8) and is used only for the React Compiler via @rolldown/plugin-babel in vite.config.ts. There is no babel.config. Do not add concurrently back — vp / artisan dev replace it. After npm package changes, install on the host and in Sail.

## Fonts are vendored in resources/fonts, not fetched from Bunny
`vite.config.ts` loads Poppins and IBM Plex Mono with the `local()` provider from files committed in `resources/fonts/`. Do not switch back to `bunny()`/`google()`: the remote providers fetch the @font-face CSS plus every variant file during `vp build`, so a connect timeout on a CI runner failed the whole build (both the quality and coverage jobs build, so it was two chances to flake per run). Nothing served to the browser changed — the plugin always emitted the files into `public/build`, and the app CSP never allowed fonts.bunny.net (that entry is Telescope-only).

Two traps when touching this:
- Declare weights explicitly via `variants`. Do NOT use the shorthand `src:` directory form — `inferWeightFromFilename()` reads filename segments right-to-left and matches `normal` as a weight keyword for 400, so Bunny's own `poppins-latin-700-normal.woff2` naming resolves to 400 and collapses every weight into one face. The vendored files are named `<slug>-<weight>.<ext>` for this reason.
- Adding a weight or subset now means downloading that file into `resources/fonts/` too. There is no network fallback; a missing file fails the build with "Local font file not found", which is intended.

Incidental fix: with a `unicode-range` present the plugin emits one @font-face per file, so the woff rule shadowed the woff2 rule for identical descriptors. The local provider emits one rule per variant with both formats in a single `src`, so browsers now negotiate woff2 properly.
