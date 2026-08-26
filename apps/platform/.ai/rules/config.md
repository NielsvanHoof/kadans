---
paths:
  - 'config/**'
  - config/queue.php
  - config/horizon.php
---

# Config

## Production config is validated at boot — add new unsafe keys to the validator
`App\Platform\Services\Production\ProductionConfigurationValidator` **fails boot** in production on unsafe config: debug on, non-HTTPS `APP_URL`, cache/queue/session not Redis, Redis without TLS, session not encrypted/secure/domain-scoped or `same_site=none`, filesystem not S3, broadcasting not Reverb, OCR driver not Textract, transcription driver not Transcribe, Scout driver `collection`/`null`, empty Sentry DSN, a log stack of only file channels (`single`/`daily`), non-delivering mailer, dev Reverb credentials, trusted proxies unset or `REMOTE_ADDR`/`*`.

The validator guards **production and staging** (`SHIPPED_ENVIRONMENTS`), not production alone. Staging ships the same image and serves the same kind of data, so an `APP_ENV=staging` container that skipped these checks would be a weaker environment wearing production's clothes. Add new environments that face real traffic to that constant.

Telescope is deliberately **not** checked here: it is in composer's `dont-discover` and `AppServiceProvider` only registers it under `environment('local')`, so config cannot switch it on in production.

When you add a config key that would be dangerous with its development default, add a check here rather than trusting the deploy. Follow the existing private-predicate style: each check returns the offending key name or `null` into the `$invalidKeys` filter.

`phpunit.xml` deliberately pins `SECURITY_CSP_*` and `SECURITY_HSTS_*` so a developer's local `.env` cannot mask a broken policy. Keep new security config pinned the same way.

Config must stay `config:cache`-serializable — register class names, not Closures (see `configureDebugbarStorageOpen`).

## Redis retry_after must exceed every job timeout
Redis retry_after must be strictly greater than every job timeout (largest: GenerateDataSubjectExportJob 600s). Default and .env.example are 660. ProductionConfigurationValidator fails boot when the reservation would expire while a job can still run. Do not lower the DSR export timeout to dodge this — the archive can contain every file for one client.

## Horizon owns Redis workers on Cloud
Laravel Cloud runs one custom background process `php artisan horizon` (not queue:work, not Managed Queues). Keep QUEUE_CONNECTION=redis. Supervisors must drain WorkerQueues::NAMES; timeout must sit above GenerateDataSubjectExportJob (600s) and below redis retry_after (660). Dashboard access outside local is HORIZON_ALLOWED_EMAILS. Leave textract:consume / transcribe:consume as separate daemons.

## Sessions live on Redis DB 2
When session.driver is redis, SESSION_CONNECTION must be the dedicated `session` Redis connection on REDIS_SESSION_DB (default 2), not the default connection (DB 0, queue/Horizon) or cache (DB 1). ProductionConfigurationValidator rejects a missing connection or a session DB that equals default. Numbered DBs share instance maxmemory — this is FLUSHDB isolation, not eviction isolation.
