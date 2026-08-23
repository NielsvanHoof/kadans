---
id: TASK-43.5
title: Replace the release pipeline and delete the container build
status: Done
assignee: []
created_date: '2026-08-22 16:05'
updated_date: '2026-08-23 13:33'
labels: []
dependencies: []
parent_task_id: TASK-43
priority: medium
type: chore
ordinal: 101000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Laravel Cloud builds and deploys from git, so the bake-based image pipeline and the three-phase pulumi release are no longer needed.

- Delete .github/workflows/release.yml. Replace with push-to-deploy or a deploy hook invoked from a small workflow. Rollback becomes redeploying an older commit rather than setting IMAGE_TAG.
- Delete the deployment-artifact job from .github/workflows/platform.yml. This removes the Trivy scan of the OCI tarball; composer audit and npm audit already run in ci:quality, but the loss of image scanning should be a conscious decision.
- Narrow .github/workflows/deploy.yml to the data-plane stack: drop cacheAuthToken, originVerifySecret, reverbAppId, reverbAppSecret, wafEnabled and imageTag; add databasePassword.
- Delete docker/production/, docker/staging/ and the production/staging/base bake targets. Keep docker/8.5 (Sail), docker/pgsql, docker/floci and compose.yaml: local development is unaffected.
- ContainerImageLayoutTest asserts the deleted Dockerfiles and entrypoint roles. Its genuinely useful part is the check that the worker queue list covers every ->onQueue() literal; port that to read a single PHP constant that the Laravel Cloud background-process command also references.
- Pulumi.staging.yaml contains only a region today, so staging is not deployable and must be filled in.
- Update .ai/rules/docker.md and .ai/rules/infra.md, rewrite infra/README.md, and record the new constraints with record-rule.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Pushing to the deployment branch deploys via Laravel Cloud with no image build in CI
- [x] #2 No workflow references ECR, bake, ecs run-task or the three-phase pulumi release
- [x] #3 A test still fails when a job is added on a queue the worker command does not drain
- [x] #4 docker/8.5, docker/pgsql, docker/floci and compose.yaml still provide a working local environment
- [x] #5 infra/README.md, .ai/rules/docker.md and .ai/rules/infra.md describe the deployed architecture accurately
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Deleted release.yml, deployment-artifact job, docker/{production,staging,base}, docker-bake.hcl. Narrowed deploy.yml to databasePassword + data-plane outputs. Added WorkerQueues constant + WorkerQueueCoverageTest. Updated docker/infra rules and infra/README. Verified: WorkerQueueCoverageTest (2), infra npm test (25).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Removed ECS/bake release path; Laravel Cloud push-to-deploy is the app release mechanism. CI is platform.yml + data-plane deploy.yml only. WorkerQueues::csv() is the queue drain contract for Cloud workers.
<!-- SECTION:FINAL_SUMMARY:END -->
