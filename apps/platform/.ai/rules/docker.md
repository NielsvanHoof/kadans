---
paths:
  - 'docker/**'
  - '.dockerignore'
---

# Docker

## Local Sail only
`docker/8.5/` is the local Sail image (`sail:publish` output plus the Octane `Caddyfile`) — edit there, not in `vendor/laravel/sail/runtimes/`, which `composer install` overwrites. `docker/pgsql/` and `docker/floci/` support Sail services in `compose.yaml`.

Deployed images and bake targets were removed in TASK-43.5 — Laravel Cloud builds from git.

## `.dockerignore` is a credential boundary
`.env` / `.env.*` only cover top-level files. `env/profiles/aws.env` holds real IAM keys, is gitignored, and would have been baked into any locally built image by `COPY . .` — hence the bare `env` entry. Any new host-only secret path needs an entry here as well as in `.gitignore`.

## Queue workers on Cloud
Production/staging workers run as Laravel Cloud background processes. The worker queue field must equal `App\Platform\Support\WorkerQueues::csv()` (`default,ocr,transcription,dossier-export`). Adding a job on a new queue means updating `WorkerQueues::NAMES`; `tests/Feature/Platform/WorkerQueueCoverageTest.php` fails if a dedicated `onQueue()` literal is missing.
