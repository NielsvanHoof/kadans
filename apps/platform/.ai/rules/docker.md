---
paths:
  - 'docker/**'
  - .dockerignore
  - vite.config.ts
---

# Docker

## Local Sail only
`docker/8.5/` is the local Sail image (`sail:publish` output plus the Octane `Caddyfile`) — edit there, not in `vendor/laravel/sail/runtimes/`, which `composer install` overwrites. `docker/pgsql/` and `docker/floci/` support Sail services in `compose.yaml`.

Deployed images and bake targets were removed in TASK-43.5 — Laravel Cloud builds from git.

## `.dockerignore` is a credential boundary
`.env` / `.env.*` only cover top-level files. `env/profiles/aws.env` holds real IAM keys, is gitignored, and would have been baked into any locally built image by `COPY . .` — hence the bare `env` entry. Any new host-only secret path needs an entry here as well as in `.gitignore`.

## Queue workers on Cloud
Production/staging drain Redis via Laravel Horizon: a single custom background process `php artisan horizon` (not `queue:work`, not Managed Queues). Supervisors in `config/horizon.php` use `App\Platform\Support\WorkerQueues::NAMES` (`default,ocr,transcription,dossier-export`). Adding a job on a new queue means updating `WorkerQueues::NAMES`; `tests/Feature/Platform/WorkerQueueCoverageTest.php` fails if a dedicated `onQueue()` literal is missing or Horizon's queue list drifts. Keep `textract:consume` / `transcribe:consume` as separate custom daemons.

## Sail Caddyfile must not set Referrer-Policy
The application owns Referrer-Policy exclusively. docker/8.5/Caddyfile is the only remaining Caddyfile after deployed images were removed (TASK-43.5) and must not set the header — Caddy's bare `header` directive replaces the field and would downgrade portal `no-referrer`. Guarded by tests/Feature/Security/ProductionCaddyfileTest.php. Do not reintroduce docker/production or docker/staging Caddyfiles; Laravel Cloud builds from git.

## Floci bucket CORS is required for browser PUTs
The browser PUTs to Floci at http://localhost:4566 from http://localhost, which is cross-origin. docker/floci/init/ready.d/01-create-local-bucket.sh must put-bucket-cors with PUT + content-type for http://localhost and http://127.0.0.1 (same allowlist as infra local uploadAllowedOrigins). Do not use a wildcard origin: a presigned URL is a bearer credential. The Laravel app CORS/CSP settings are never consulted for this request — a missing bucket rule is a 403 preflight. The script is bind-mounted; applying CORS to a bucket that already exists still needs a Floci restart or a one-shot aws s3api put-bucket-cors, because ready.d only runs at container start.

## React Compiler via rolldown Babel; SSR off in Vite
Wire babel-plugin-react-compiler with @rolldown/plugin-babel + reactCompilerPreset() from @vitejs/plugin-react (v6 dropped inline babel). Keep inertia({ ssr: false }) aligned with config/inertia.php enabled=false — do not reintroduce build:ssr without an SSR entry, production SSR process, and Cloud toggle together.
