# Getting started

Everything runs through [Laravel Sail](https://laravel.com/docs/sail). Prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`.

## Prerequisites

- Docker Desktop (or another Docker engine)
- Composer on the host (for the initial `composer install` / `vendor/bin/sail` binary)

## Recommended first boot

`composer run setup` runs `migrate` on the host before containers exist, so the database may not be reachable yet. Prefer this order on a fresh clone:

```bash
composer install
cp .env.example .env          # skip if .env already exists
vendor/bin/sail up -d         # Postgres, Redis, Mailpit, Floci, Octane
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --force
vendor/bin/sail npm install
vendor/bin/sail npm run build
```

Then start the long-running side processes (queue, logs, Reverb, Vite):

```bash
vendor/bin/sail artisan dev
```

After changing `compose.yaml` or the local image files in `docker/8.5/`, rebuild:

```bash
vendor/bin/sail build --no-cache
vendor/bin/sail up -d
```

### Shortcut

If Sail is already up and healthy, `composer run setup` is fine for subsequent machines. Otherwise use the manual path above.

## First user and firm

Create a verified workspace owner and provision their firm:

```bash
vendor/bin/sail artisan tenancy:create-owner
```

You can pass arguments/options (`name`, `email`, `--firm`, `--password`, `--role`) or answer the prompts.

Roles and permissions are synced when a tenant is provisioned. To re-sync every tenant after you change permission enums:

```bash
vendor/bin/sail artisan tenancy:sync-roles
# optional: remove Spatie permissions no longer declared in code
vendor/bin/sail artisan tenancy:sync-roles --prune
```

## Useful URLs

| Service | URL |
|---------|-----|
| App | http://localhost |
| Mailpit | http://localhost:8025 |
| Floci (local AWS) | http://localhost:4566 |
| Floci UI | http://localhost:4566/_floci/ui |

Default filesystem is local. For S3 against Sail's Floci emulator, run `composer env:floci` (sets `FILESYSTEM_DISK=s3`, bucket `local`, path-style endpoint). Keep mock OCR/transcription unless you intentionally use real AWS — see [Storage profiles](#storage-profiles) and [infra/README](../infra/README.md).

### Storage profiles

Flip only the AWS/storage-related keys in `.env` without replacing the whole file:

```bash
composer env:floci   # Sail Floci S3 + mock OCR/transcription (committed overlay)
composer env:aws     # real AWS from cached Pulumi profile (or sync if missing)
```

- **floci** — applies [`env/profiles/floci.env`](../env/profiles/floci.env) (dummy creds, safe to commit). Safe via Sail: `vendor/bin/sail composer env:floci`.
- **aws** — applies gitignored `env/profiles/aws.env`. Run on the **host** (not Sail): Pulumi CLI + stack credentials are outside the container. First time (or after infra changes): `composer env:aws`, which refreshes the cache via `infra/scripts/sync-ocr-credentials.sh` when needed.

After either command:

```bash
vendor/bin/sail artisan config:clear
# restart vendor/bin/sail artisan dev if it is already running
```

### Local AWS via Floci (optional)

[Floci](https://github.com/floci-io/floci) emulates S3 plus stub Textract/Transcribe/SQS/SNS on one endpoint. Prefer `composer env:floci` over hand-editing `AWS_*` so you do not mix a Floci endpoint with a Pulumi bucket name.

Fidelity limits — do not treat Floci as a full OCR/STT substitute:

- Textract/Transcribe return fixed stub payloads (no real OCR or speech-to-text).
- Floci does **not** publish Textract `NotificationChannel` completions onto SNS→SQS, so `textract:consume` never sees a job finish.
- Floci Transcribe does **not** write transcript JSON to S3 or emit EventBridge→SQS completion events for `transcribe:consume`.

For Start-only SDK smoke tests against Floci you can set `OCR_DRIVER=textract` / `TRANSCRIPTION_DRIVER=transcribe` with Floci ARNs/queue URLs you create yourself. For an end-to-end completion path, use `composer env:aws` (or keep mock drivers).

`php artisan dev` only starts `textract:consume` / `transcribe:consume` when those drivers are enabled **and** the matching results queue URL is set.

## Day-to-day commands

```bash
vendor/bin/sail up -d
vendor/bin/sail composer run dev          # queue + logs + Reverb + Vite
vendor/bin/sail artisan test --compact    # Pest
vendor/bin/sail npm run types:check       # Wayfinder generate + tsc
vendor/bin/sail npm run lint:check        # Biome
vendor/bin/sail npm run format:check      # Prettier
vendor/bin/sail bin pint --dirty          # PHP formatting
```

Octane reloads workers when backend files change. `actions/` and `routes/` under `resources/js` are Wayfinder-generated and gitignored — run `dev` or `build` so IDE types resolve.

Scoped tests:

```bash
vendor/bin/sail artisan test --compact tests/Feature/Dossiers/
vendor/bin/sail artisan test --compact --filter='portal'
```

## Architecture notes for local work

- **Shared database tenancy** — one Postgres database; tenants are rows, not separate DBs. HTTP middleware sets the current tenant; console/async code uses `TenantContext::runForTenant()`.
- **Octane** — the app boots once and serves many requests. Do not store request-specific state in singletons or static properties.
- **OCR** — default driver is `mock` (see `OCR_DRIVER` in `.env`). Floci can stub Textract Start* locally; completion still needs mock or real AWS — see the Floci section above and [infra/README](../infra/README.md).
