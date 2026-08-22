---
id: TASK-43.4
title: Stand up Laravel Cloud environments
status: To Do
assignee: []
created_date: '2026-08-22 16:05'
updated_date: '2026-08-22 16:55'
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
<!-- SECTION:NOTES:END -->
