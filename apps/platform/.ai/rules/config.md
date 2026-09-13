---
paths:
  - 'config/**'
  - config/queue.php
  - config/horizon.php
  - config/permission.php
  - config/ai.php
---

# Config

## Production config is validated at boot — add new unsafe keys to the validator
`App\Platform\Services\Production\ProductionConfigurationValidator` **fails boot** in production on unsafe config: debug on, non-HTTPS `APP_URL`, session not Redis, Redis without TLS, session not encrypted/secure/domain-scoped or `same_site=none`, filesystem not S3, broadcasting not Reverb, OCR driver not Textract, transcription driver not Transcribe, Scout driver `collection`/`null`, empty Sentry DSN, a log stack of only file channels (`single`/`daily`), non-delivering mailer, dev Reverb credentials, trusted proxies unset or `REMOTE_ADDR`/`*`.

The validator guards **production and staging** (`SHIPPED_ENVIRONMENTS`), not production alone. Staging ships the same image and serves the same kind of data, so an `APP_ENV=staging` container that skipped these checks would be a weaker environment wearing production's clothes. Add new environments that face real traffic to that constant.

Telescope is deliberately **not** checked here: it is in composer's `dont-discover` and `AppServiceProvider` only registers it under `environment('local')`, so config cannot switch it on in production.

When you add a config key that would be dangerous with its development default, add a check here rather than trusting the deploy. Follow the existing private-predicate style: each check returns the offending key name or `null` into the `$invalidKeys` filter.

`phpunit.xml` deliberately pins `SECURITY_CSP_*` and `SECURITY_HSTS_*` so a developer's local `.env` cannot mask a broken policy. Keep new security config pinned the same way.

Config must stay `config:cache`-serializable — register class names, not Closures (see `configureDebugbarStorageOpen`).

`cache.default` and `queue.default` are deliberately **not** checked (removed 2026-08-27). Do not re-add them as "missing" — the omission is a decision, not an oversight. The consequence is accepted and worth knowing: a shipped environment will boot on `QUEUE_CONNECTION=sync`, which runs OCR, Bedrock calls and dossier exports inline in the web request with no Horizon and no retries, and on any `CACHE_STORE`. Both keys are still expected to be `redis` in `.env`; nothing enforces it. (The keys themselves were always correct — `matches()` reads the config path, which `config/cache.php` and `config/queue.php` fill from `CACHE_STORE` / `QUEUE_CONNECTION`; there is no `CACHE_DEFAULT` env var.)

## Redis retry_after must exceed every job timeout
Redis retry_after must be strictly greater than every job timeout (largest: GenerateDataSubjectExportJob 600s). Default and .env.example are 660. ProductionConfigurationValidator fails boot when the reservation would expire while a job can still run. Do not lower the DSR export timeout to dodge this — the archive can contain every file for one client.

## Horizon owns Redis workers on Cloud
Laravel Cloud runs one custom background process `php artisan horizon` (not queue:work, not Managed Queues). Keep QUEUE_CONNECTION=redis. Dashboard access outside local is HORIZON_ALLOWED_EMAILS. Leave textract:consume / transcribe:consume as separate daemons.

Two supervisors, `supervisor-interactive` (WorkerQueues::INTERACTIVE) and `supervisor-heavy` (WorkerQueues::HEAVY), must partition WorkerQueues::NAMES exactly — a queue in neither fails nothing, it just piles up unconsumed. The supervisor draining `dossier-export` needs a timeout above GenerateDataSubjectExportJob (600s) and below redis retry_after (660).

Every `maxProcesses` must stay strictly above its own supervisor's queue count. `balance => 'auto'` builds one pool per queue and `minProcesses` is a per-pool floor, so `AutoScaler::scalePool` derives `maxUpShift` from `max(0, maxProcesses - totalProcessCount)`; at or below the floor that is 0 and the supervisor can never scale up, silently, with `balance => 'auto'` still looking correct in the config. One supervisor over all five queues held 5 x 256 MB resident this way and triggered a 90% memory alert on a 2 GiB cluster (TASK-45).

`memory` is a recycle threshold, not a cap — Worker::stopIfNecessary checks it between jobs, so a job needing more is bounded by PHP's memory_limit and still finishes. With maxJobs/maxTime at 0 it is also the only recycle trigger, which makes it a target every worker climbs to; keep maxJobs set.

## Sessions live on Redis DB 2
When session.driver is redis, SESSION_CONNECTION must be the dedicated `session` Redis connection on REDIS_SESSION_DB (default 2), not the default connection (DB 0, queue/Horizon) or cache (DB 1). ProductionConfigurationValidator rejects a missing connection or a session DB that equals default. Numbered DBs share instance maxmemory — this is FLUSHDB isolation, not eviction isolation.

## Spatie permission cache does NOT re-hydrate per request under Octane
`PermissionRegistrar` is bound as a `singleton`, so each FrankenPHP worker holds the whole permission collection in memory for its lifetime. `forgetCachedPermissions()` clears the Redis key **and only the calling worker's** copy — every other worker keeps serving its stale collection until it recycles.

Consequence (a real 403 bug, reproduced 2026-09-01): provisioning a firm runs `SyncTenantRolesAction`, which creates that tenant's roles. Workers that cached beforehand cannot see role rows for the new tenant, so `$user->can('dossiers.view')` is false there and `DashboardController@show`'s `authorize('viewAny', Dossier::class)` throws. The owner's first visits to the new workspace dashboard then alternate 200/403 depending on which worker answers, and heal on their own once workers recycle — which is what makes it look intermittent. The audit log records it as `access.denied` / "This action is unauthorized.".

Fix in place: `permission.register_octane_reset_listener => true` (the package's own `OperationTerminated` listener). Guarded by a test in `tests/Feature/Platform/OctaneConfigurationTest.php`. Do not set it back to the vendor default of `false`.

Corrects the claim in `.ai/rules/app.md` ("Known scaling ceilings") that the registrar "re-hydrates per request" — it hydrates once per worker. With the listener on, it now genuinely does re-read the Redis blob every request, so that entry's cost estimate is the accurate one.

## Spatie must not Gate-check policy abilities
Keep `permission.register_permission_check_method` false. Spatie's Gate::before looks up every ability as a permission name, so `authorize('viewAny', …)` throws PermissionDoesNotExist and logs missing vendor translation copy. TenancyServiceProvider owns the Gate::before: registered PermissionRegistry names (plus the support-session overlay), then policies. Guarded by `tests/Feature/Tenancy/PermissionGateTest.php`.

## Titan embeddings have no eu. profile
Titan Text Embeddings V2 has no geographic inference profile. Use amazon.titan-embed-text-v2:0, never eu.amazon.titan-embed-text-v2:0. Nova uses the eu. prefix; Titan does not. The wrong id InvokeModels successfully with an empty body, and Laravel AI throws EmbeddingsCountMismatchException. Octane only reads .env at boot — changing AI_BEDROCK_EMBEDDINGS_MODEL needs a Sail restart unless config/ai.php itself changed.
