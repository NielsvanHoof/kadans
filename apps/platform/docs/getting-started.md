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

### Local (Sail)

Seed the production-safe baseline (roles + system questionnaire templates), then create a verified workspace owner:

```bash
vendor/bin/sail artisan db:seed --force
vendor/bin/sail artisan tenancy:create-owner
```

You can pass arguments/options (`name`, `email`, `--firm`, `--password`, `--role`) or answer the prompts.

Local-only entrypoint. On top of the same baseline it runs `DemoDataSeeder`, which builds a full demo firm — staff across three roles, 14 clients, dossiers in every status, processed documents with extractions, a transcript, and one deliberately failed pipeline — then prints the sign-in details and a ready-to-open client portal link:

```bash
vendor/bin/sail artisan db:seed --class=LocalDatabaseSeeder --force
```

Never run `LocalDatabaseSeeder` on Cloud/staging/production — it aborts outside `local`/`testing`.

Two details in `DemoDataSeeder` are deliberate and easy to break: `answered_at` is spread across the eight weeks `FetchDashboardTrendQuery` buckets (data created "now" renders as seven zero columns and a spike), and the faker seed is pinned so every run produces identical data. `tests/Feature/Platform/DemoDataSeederTest.php` guards both.

### Laravel Cloud (staging / production)

Cloud commands have no TTY, so interactive prompts fail. Pass all values as CLI args:

```bash
cloud command:run staging -n --cmd='php artisan db:seed --force && php artisan tenancy:create-owner "Your Name" "you@example.com" --firm="Your Firm" --password="…" --role=owner'
```

- `db:seed` is production-safe: syncs roles and **creates missing** system templates only. It never updates/deletes existing template items and never creates users.
- `tenancy:create-owner` is a deliberate one-off (not a seeder). Re-running with the same email fails validation.
- Omit `--password` / `--role` in non-interactive mode to generate a password and default to `owner`.

### Provisioning a new customer later

| Case | How |
|------|-----|
| New firm | `tenancy:create-owner` with all args via `cloud command:run` (same as above) |
| Join existing firm | In-app member invite |
| Self-serve | Public registration → onboarding creates the firm |

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

Default filesystem is local. For the production-shaped local AWS path, run `composer env:floci`: it enables Floci-backed S3, sends mail through Floci SES into Mailpit, records document S3 access in a local CloudTrail bucket, and points Bedrock Runtime at Floci. Keep mock OCR/transcription unless you intentionally use real AWS — see [Storage profiles](#storage-profiles) and [infra/README](../infra/README.md).

### Storage profiles

Flip only the AWS/storage-related keys in `.env` without replacing the whole file:

```bash
composer env:floci   # Sail Floci S3/SES/CloudTrail/Bedrock + mock OCR/transcription
composer env:aws     # real AWS local stack (Sail) — cached from `pulumi up -s local`
composer env:aws-staging  # print/sync staging stack outputs (Laravel Cloud vars)
```

- **floci** — applies [`env/profiles/floci.env`](../env/profiles/floci.env) (dummy creds, safe to commit). Safe via Sail: `vendor/bin/sail composer env:floci`.
- **aws** — applies gitignored `env/profiles/aws.env` (local stack). Run on the **host**: `composer env:aws`.
- **aws-staging** — applies `env/profiles/aws-staging.env` after `pulumi up -s staging`. Use stack outputs for Laravel Cloud env vars; **never** point Cloud at the local Textract SQS queue.

After either command:

```bash
vendor/bin/sail artisan config:clear
# restart vendor/bin/sail artisan dev if it is already running
```

### Local AWS via Floci (optional)

[Floci](https://github.com/floci-io/floci) emulates the local AWS APIs on one endpoint. Prefer `composer env:floci` over hand-editing `AWS_*` so you do not mix a Floci endpoint with a Pulumi bucket name.

The profile uses Laravel's real SES transport. Floci verifies the local `qompose.test` domain during bootstrap, stores messages at `http://localhost:4566/_aws/ses`, and relays them to Mailpit. It also records S3 object access for the `local` documents bucket into `local-audit-logs`; Floci flushes AWS-shaped gzipped CloudTrail files every five seconds.

`AWS_ENDPOINT_URL_BEDROCK_RUNTIME` points Laravel AI's underlying AWS SDK client at Floci without overriding Laravel AI internals. Floci returns stub model output by default, so this exercises Bedrock request signing and response handling rather than model quality. Structured-output agents may reject the fixed stub payload; use Floci's optional OpenAI-compatible proxy backend when schema-valid model output is required.

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
vendor/bin/sail npm run check             # Oxfmt + Oxlint
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
