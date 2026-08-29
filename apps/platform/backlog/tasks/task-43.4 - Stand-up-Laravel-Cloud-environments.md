---
id: TASK-43.4
title: Stand up Laravel Cloud environments
status: Done
assignee: []
created_date: '2026-08-22 16:05'
updated_date: '2026-08-28 22:38'
labels: []
dependencies: []
parent_task_id: TASK-43
priority: high
type: feature
ordinal: 100000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Create and configure the Laravel Cloud application and its staging and production environments in eu-west-1, matching the data plane region so S3/SQS/Textract calls stay in-region.

Topology replacing the seven ECS roles:
- App cluster with the Octane runtime and the scheduler toggle (replaces the web role and the EventBridge rate(1 minute) RunTask scheduler).
- Worker cluster running three background processes: queue:work over default,ocr,transcription,dossier-export with default first so interactive jobs are not starved behind an OCR run; and custom processes for textract:consume and transcribe:consume, which are long-poll SQS daemons rather than queue workers.
- Laravel Valkey for cache, session and queue (ElastiCache has no public endpoint, so this move is forced).
- A managed Reverb WebSocket cluster, which injects both the server REVERB_* and the build-time VITE_REVERB_* variables.
- Deploy command php artisan migrate --force, which runs before traffic shifts and fails the deploy on error, preserving the migrate-before-roll guarantee.

Worker sizing is load-bearing: GenerateDataSubjectExportJob stages an entire client archive on local disk before streaming the zip to S3, and Cloud provides 512 MB of ephemeral disk per 1 GB of RAM against a 500 MB per-dossier portal cap.

Note that php artisan optimize bakes config/reverb.php allowed_origins (computed from APP_URL) into the config cache, so APP_URL must be present in the build environment.

Replacing the ECS task role with a long-lived IAM user access key is a real regression in credential posture and is unavoidable outside Private Cloud. The app-access policy is already tightly scoped; key rotation belongs in the runbook.

Only app.qompose.nl is needed: tenants resolve from the session, not the hostname, so the unused *.qompose.nl SAN should not be recreated.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Both environments deploy successfully, which implies ProductionConfigurationValidator passed at boot
- [ ] #2 GET /ready returns 200, exercising Postgres, Redis and S3 in one probe
- [ ] #3 Queue jobs, scheduled tasks and both SQS consumer daemons run without manual intervention
- [ ] #4 The browser opens a WebSocket to the managed Reverb host and receives live pipeline updates
- [ ] #5 A data subject export completes without exhausting worker disk
- [ ] #6 AWS credentials are stored in Laravel Cloud Secrets Manager and key rotation is documented
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Approach change: the `laravel/cloud-cli` package exposes the whole topology as CRUD commands (`application`, `environment`, `instance`, `database-cluster`, `cache`, `bucket`, `domain`, `websocket-cluster`, `background-process`, `deployment`), plus `cloud deploy` / `deploy:monitor` and `cloud environment:variables`. Provision through the CLI in a committed script rather than by clicking the dashboard: it is reviewable, repeatable across staging and production, and matches how the rest of this repo treats infrastructure.

`cloud tinker {env} --code=` and `cloud command:run {env} --cmd=` also give a way to run the post-cutover verification (the /ready probe, schedule:list, a dispatched job) from CI rather than by hand.

Blocked on the user for two things only they can do:
- `composer global require laravel/cloud-cli` (installs outside the project)
- `cloud auth -n`, which opens a browser; alternatively set LARAVEL_CLOUD_TOKEN

Neither is done yet: `cloud` is not on PATH, there is no ~/.config/cloud/config.json and no repo-local .cloud/config.json.

## Progress (2026-08-22)

- Auth is working (`cloud` on PATH via ~/.composer/vendor/bin; token present). Application list is empty after delete.
- Attempted recreate in `eu-west-1` via CLI. Blocked: this repo is a monorepo (Laravel app lives at `apps/platform`). Cloud probes the git root and finds no app there.
- Official docs: monorepo root-directory selection is an interactive step during application creation (dashboard/UI). See https://laravel.com/cloud/docs/monorepos
- Confirmed `laravel/cloud-cli` **0.5.2** has no monorepo concept:
  - `CreateApplicationRequestData` sends only `repository`, `name`, `region`
  - `application:create` / `ship` / `environment:update` expose no `--base-directory` / root-directory flag
- Implication: first app create for this monorepo must be done in the Laravel Cloud dashboard (select `apps/platform`). After that, remaining topology (envs, Valkey, Reverb, workers, vars, deploy) can stay on the CLI.

## Progress (2026-08-22 evening)

Production:
- Valkey + Reverb attached; Octane + scheduler; hibernation off (use --scale-to-zero=0, not false)
- Deploy: php artisan migrate --force
- BG processes on App: queue (default,ocr,transcription,dossier-export), textract:consume, transcribe:consume
- Domain skipped (vanity laravel.cloud)

Staging:
- Env on branch staging with own Valkey + Reverb app; same topology as production

Blocked for deploy: env vars beyond APP_KEY (DB/AWS/S3/etc).

Correction (2026-08-27): the worker topology in this task's description predates the Horizon decision and is wrong as written. Cloud does not run three background processes with queue:work over default,ocr,transcription,dossier-export.

Actual topology: one custom background process 'php artisan horizon', plus textract:consume and transcribe:consume as separate long-poll SQS daemons. Horizon itself forks workers from config/horizon.php, which since TASK-45 has two supervisors — supervisor-interactive over WorkerQueues::INTERACTIVE (default, broadcasts) and supervisor-heavy over WorkerQueues::HEAVY (ocr, transcription, dossier-export). The queue CSV a managed queue:work worker would need is obsolete; do not provision Managed Queues alongside Horizon.

Worker sizing note in the description still holds in spirit but not in numbers: the app cluster is 2 GiB / 1 vCPU and runs web, scheduler and all three background processes together, which hit a 90% memory alert until TASK-45 retuned the supervisors. See .ai/rules/config.md.
<!-- SECTION:NOTES:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: claude
created: 2026-08-28 22:38
---
Closed on the author's confirmation that the Cloud environments are up. The individual acceptance criteria are left unchecked rather than ticked on inference: /ready, the Reverb WebSocket handshake, the daemons running unattended, and a data-subject export completing within worker disk are all observations against the deployed environments, and AWS credentials here return an expired token. Anyone with Cloud access can check them off in one pass.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Laravel Cloud application and both environments are standing and serving. Confirmed by the author on 2026-08-29; staging additionally runs Nightwatch, configured through Cloud environment variables rather than a committed config file.

The "Blocked for deploy: env vars beyond APP_KEY" note in the progress log is resolved and no longer describes reality.

Topology as actually deployed is the corrected one in the notes, not the one in this task's description: one `php artisan horizon` background process (Horizon forks its own workers from the two supervisors in config/horizon.php) plus `textract:consume` and `transcribe:consume` as long-poll SQS daemons. Do not provision Managed Queues alongside Horizon.
<!-- SECTION:FINAL_SUMMARY:END -->
