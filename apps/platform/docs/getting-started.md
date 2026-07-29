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
vendor/bin/sail up -d         # Postgres, Redis, Mailpit, MinIO, Octane
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --force
vendor/bin/sail npm install
vendor/bin/sail npm run build
```

Then start the long-running side processes (queue, logs, Reverb, Vite):

```bash
vendor/bin/sail artisan dev
```

After changing `compose.yaml`, rebuild:

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
| MinIO console | http://localhost:8900 |

Default filesystem is local. If you switch to S3/MinIO, create the bucket named in `.env` (see comments in `.env.example`).

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
- **OCR** — default driver is `mock` (see `OCR_DRIVER` in `.env`). Real Textract/Bedrock setup is optional; see [infra/README](../infra/README.md).
