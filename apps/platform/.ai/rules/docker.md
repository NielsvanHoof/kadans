---
paths:
  - 'docker/**'
  - 'docker-bake.hcl'
  - '.dockerignore'
---

# Docker

## One directory per environment; deployed images share a base through bake
`docker/8.5/` is the local Sail image (`sail:publish` output plus the Octane `Caddyfile`) — edit there, not in `vendor/laravel/sail/runtimes/`, which `composer install` overwrites. `docker/base/` holds the shared deployed build (PHP extensions, Composer install, Vite build). `docker/staging/` and `docker/production/` each own a `Dockerfile`, `Caddyfile`, `php.ini` and `entrypoint.sh`.

Docker has no include directive, so a stage in one Dockerfile cannot be `FROM`'d by another. `docker-bake.hcl` closes that with `contexts = { base = "target:base" }`, which wires `docker/base` in as a named build context — separate per-environment Dockerfiles, one build graph, no registry round-trip. Consequences:

- Build through bake, never `docker build`: `docker buildx bake -f docker-bake.hcl --load production`. A bare `docker build -f docker/production/Dockerfile` cannot resolve `FROM base`.
- Pass `-f docker-bake.hcl`. Buildx also auto-loads `compose.yaml` as a bake definition, and the explicit file keeps the graph to these targets.
- CI uses `docker/bake-action`, not `docker/build-push-action` — the latter cannot resolve a `target:` context. It reports per-target JSON on `outputs.metadata`, so the OCI digest comes from `.production["containerimage.digest"]`, not a bare `digest` output.
- The OCI exporter needs the `docker-container` driver. `setup-buildx-action` provides it in CI; locally the default `docker` driver fails with "OCI exporter is not supported".
- `VITE_REVERB_*` is compiled into the JS bundle, so an image cannot be repointed at another Reverb host. The base carries those args and is rebuilt per environment invocation.

## The deployed image dispatches on a role
`entrypoint.sh` takes a role as the container command — `web` (the `CMD` default), `worker`, `reverb`, `schedule`, `migrate`, `textract-consumer`, `transcribe-consumer` — so ECS runs one artifact as several services (TASK-34.2). Anything unrecognised is `exec "$@"`.

`php artisan optimize` runs only for the recognised roles. Do **not** hoist it above the `case`: it boots the app, so a container with broken configuration could not be inspected — precisely when a shell in it is needed.

The `worker` role drains `default,ocr,transcription,dossier-export` with `default` first, so interactive jobs are not starved behind an OCR run. `WORKER_QUEUES` overrides the list for per-queue services. Adding a job on a new queue means adding it here; `tests/Feature/Platform/ContainerImageLayoutTest.php` reads the `onQueue()` literals off the job classes and fails if the entrypoint does not cover them. Retries and timeouts stay on the job attributes — do not pass `--tries`.

Migrations are their own one-shot role, never auto-run on web start: several tasks boot concurrently.

## .dockerignore is a credential boundary
`.env` / `.env.*` only cover top-level files. `env/profiles/aws.env` holds real IAM keys, is gitignored, and would have been baked into any locally built image by `COPY . .` — hence the bare `env` entry. Any new host-only secret path needs an entry here as well as in `.gitignore`.

## Staging is production plus an inert Xdebug
`docker/staging/php.ini` mirrors production's hardening (`display_errors=Off`, `expose_php=Off`, `session.cookie_*`) and adds `xdebug.mode=off`. Xdebug reads the `XDEBUG_MODE` environment variable and it overrides the ini, so a task is armed by restarting it with `XDEBUG_MODE=profile` — no rebuild. Composer stays `--no-dev`: dev packages would move the container away from the artifact under test. See `.ai/rules/config.md` for why the production config validator also guards staging.
