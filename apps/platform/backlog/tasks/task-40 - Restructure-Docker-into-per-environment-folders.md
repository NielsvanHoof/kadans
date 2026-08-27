---
id: TASK-40
title: Restructure Docker into per-environment folders
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-08-18 09:09'
updated_date: '2026-08-25 21:15'
labels:
  - infra
  - docker
dependencies: []
priority: high
type: chore
ordinal: 93000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Local dev builds from vendor/laravel/sail/runtimes/8.5, which composer install wipes, so there is nowhere to pin or extend the local image. Production is a single root Dockerfile.production sharing no base image, extension set or php.ini with local. Staging does not exist. The image runs one process, so the four production queues, the scheduler and Reverb have nothing to run in.

Move to a docker/<env>/ layout where each environment owns its Dockerfile, Caddyfile, php.ini and entrypoint.sh over a shared base stage, publish the Sail runtime into the repo, and give the image a role-dispatch entrypoint (web / worker / reverb / schedule / migrate) so TASK-34.2 Fargate web/worker/Reverb services have an artifact to deploy.

Also closes a credential gap found while scoping: .dockerignore excludes .env and .env.* but not env/, so env/profiles/aws.env (live IAM keys, gitignored) would be baked into any locally built image by COPY . .
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 env/ is excluded from the Docker build context so env/profiles/aws.env cannot reach an image layer
- [x] #2 Sail runtime files are published under docker/ and compose.yaml builds from them instead of vendor/
- [x] #3 docker/base holds the shared PHP extension set and asset build; staging and production Dockerfiles layer only their own config on top
- [x] #4 A single image serves web, worker and reverb roles, and the worker covers all four production queues
- [x] #5 Dockerfile.production and docker-bake.hcl are gone from the repo (CI's Trivy-scanned OCI artifact went with them in TASK-43.5 — recorded as a gap in docs/security.md, not silently dropped)
- [x] #6 Tests cover the per-environment file layout, the entrypoint role set and the Caddyfile Referrer-Policy invariant for both environments
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add env/, backlog, docs, tests, .ai, .claude, _ide_helper.php, .phpstorm.meta.php to .dockerignore (credential fix first).
2. Run artisan sail:publish; move docker/frankenphp/Caddyfile to docker/8.5/Caddyfile; repoint compose.yaml caddyfile path.
3. Create docker/base/Dockerfile with the php-base/build/app-base stages lifted from Dockerfile.production.
4. Rewrite docker/production/entrypoint.sh as a role dispatcher (web/worker/reverb/schedule/migrate/consumers + exec fallback); add docker/production/Dockerfile layering config on FROM base.
5. Add docker/staging/{Dockerfile,Caddyfile,php.ini,entrypoint.sh}: production parity plus an inert xdebug.
6. Add docker-bake.hcl wiring staging/production to the base via contexts = { base = "target:base" }.
7. Delete Dockerfile.production; switch the platform.yml deployment-artifact job to docker/bake-action, keeping the OCI output, gha cache and Trivy gate.
8. Extend ProductionConfigurationValidator to run under APP_ENV=staging; record it in .ai/rules/config.md.
9. Tests: per-env layout, entrypoint role set and queue list, staging php.ini hardening, .dockerignore env guard, Caddyfile Referrer-Policy across both envs, staging validator.
10. Update README.md, docs/getting-started.md for the moved local build path.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Docker has no include directive, so a stage in one Dockerfile cannot be FROM'd by another. Used buildx bake's `contexts = { base = "target:base" }` (docker-bake.hcl) to wire docker/base into each environment Dockerfile as a named build context: separate per-env Dockerfiles, one build graph, no registry round-trip, full layer caching. This is why CI had to move from docker/build-push-action to docker/bake-action - build-push-action cannot resolve a target: context.

The VITE_REVERB_* values are compiled into the JS bundle, so the base carries them and is rebuilt per environment invocation rather than shared between staging and production.

First cut of the entrypoint ran `php artisan optimize` before the `exec "$@"` passthrough. Caught during smoke testing: `docker run <image> php -v` failed on the production config validator, which meant the escape hatch was unusable exactly when a container had broken config. Now optimize runs only for the recognised roles.

Staging carries Xdebug but stays inert - xdebug.mode=off in docker/staging/php.ini, armed by restarting the task with XDEBUG_MODE set. Composer still installs --no-dev so staging behaves like the artifact that ships.

Also extended ProductionConfigurationValidator from isProduction() to SHIPPED_ENVIRONMENTS (production + staging): APP_ENV=staging previously skipped every check, so staging could boot with debug on, plaintext Redis, mock OCR drivers and the checked-in Reverb keys.

Verification:
- Local Sail: `vendor/bin/sail build` + `up -d --force-recreate laravel.test` from docker/8.5 -> GET /up 200; running process shows caddyfile=/var/www/html/docker/8.5/Caddyfile; `grep www/html /proc/mounts` still returns 2 lines, so the sail-node-modules volume is still nested (.ai/rules/local-environment.md).
- Deployed images: `docker buildx bake -f docker-bake.hcl --load production staging` succeeds; the named base context resolves.
- Roles: web serves /up 200 through Octane/FrankenPHP on the production Caddyfile; reverb logs "Starting server on 0.0.0.0:8080"; worker starts queue:work after optimize; migrate ran the full migration set against sqlite; schedule dispatched schedule:run; `php -v` passthrough works without booting the app.
- Credentials: `ls env` inside the image -> No such file or directory (also tests, backlog, docs, _ide_helper.php).
- Staging Xdebug: inert by default, engages only when the container is started with XDEBUG_MODE set.
- CI mechanism (on a throwaway docker-container builder, since the OCI exporter needs it): bake produced a 264 MB OCI tarball at the CI path pattern, --metadata-file yielded production.containerimage.digest for the summary step, and the dotted `--set production.labels.org.opencontainers.image.revision=deadbeef` landed on the image config - so buildx `--set` parses dotted label keys correctly.
- Suite: `artisan test --parallel` 1328 passed. (A first run showed 2 failures - a 1-second timestamp comparison in PipelineTransitionsTest and a config-repository race in TrustedHostsTest - both passed in isolation and on re-run; parallel flakes, not this change.) PHPStan level 9 clean, Pint clean.

Closed in the 2026-08-25 reconciliation. Verified: Dockerfile.production and docker-bake.hcl are absent; docker/ holds 8.5, floci and pgsql; ProductionCaddyfileTest still exists and passes as part of the 1410-test suite, and docs/security.md already points at docker/8.5/Caddyfile rather than the deleted per-environment ones.

AC#5 reworded. The original read 'Dockerfile.production is gone and CI still produces the same Trivy-scanned OCI artifact'. The first half happened; the second became unachievable when TASK-43.5 deleted the container build along with the ECS release path (decision-9). Rather than tick a half-true criterion or leave the task open forever, the criterion now states what is actually true and points at where the lost control is recorded — docs/security.md gained a 'No container image scanning' row under Remaining tracked work, because losing a v1.0 security control deserves a line, not silence.

Worth being explicit about ACs #3 and #6: they stay checked because the work was genuinely delivered, but what they describe — docker/base, docker/staging, docker/production and the two per-environment Caddyfiles — was deleted afterwards by TASK-43.5. This task built a per-environment Docker layout that a later decision made unnecessary. That is not a defect in this task; it is the cost of decision-9 arriving after it.
<!-- SECTION:NOTES:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: @NielsvanHoof
created: 2026-08-18 09:29
---
AC #5 left unchecked deliberately. Dockerfile.production is gone and the bake build was verified to produce the same OCI tarball and digest metadata locally on a CI-equivalent docker-container builder, but the docker/bake-action@v6 input wiring itself (workdir/files/targets/set) can only be proven by a real pipeline run. Check it once platform.yml has run green on this branch.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Per-environment Docker layout delivered; env/ excluded from the build context, role-dispatch entrypoint, and the Caddyfile Referrer-Policy invariant under test. AC#5 reworded because TASK-43.5 later removed the container build entirely — the lost Trivy scan is now recorded as tracked work in docs/security.md. Verified against the current docker/ tree and a green suite.
<!-- SECTION:FINAL_SUMMARY:END -->
