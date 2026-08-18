---
id: TASK-34.2
title: Provision lean AWS compute platform for the app
status: In Progress
assignee:
  - '@NielsvanHoof'
created_date: '2026-08-11 16:54'
updated_date: '2026-08-18 12:02'
labels:
  - infra
  - aws
  - compute
milestone: m-0
dependencies: []
references:
  - >-
    .cursor/projects/Users-nielsvanhoof-Documents-projects-qompose-apps-platform/canvases/lean-security-extras.canvas.tsx
  - 'https://www.qompose.nl'
documentation:
  - infra/README.md
  - docs/security.md
parent_task_id: TASK-34
priority: high
type: feature
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Lean core stack from the Lean + security extras canvas so the Laravel platform can run in AWS.

Target shape: public subnets without NAT; ECS Fargate ARM (web, worker, Reverb); Multi-AZ ALB with idle timeout suitable for Reverb; RDS Postgres db.t4g.micro Multi-AZ; Valkey cache.t4g.micro ×2 with TLS for sessions/cache/queue; EventBridge → RunTask for schedule:run; Secrets Manager + SES; reuse existing app task role / OIDC deploy path and documents stack.

Must support serving app.qompose.nl and future {slug}.qompose.nl via ACM (app + wildcard) and DNS records that leave www/apex on the marketing host. Reserved system hosts (mail, api, …) must remain carve-outs, not swallowed as tenants.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 VPC, ALB, RDS Multi-AZ micro, Valkey×2 TLS, and ECS Fargate web/worker/Reverb services are provisioned via the project IaC approach
- [ ] #2 ACM covers app.qompose.nl and *.qompose.nl; DNS for app and the tenant wildcard points at the platform edge without moving www marketing
- [ ] #3 Secrets Manager holds production secrets; SES is available as the production mailer
- [ ] #4 EventBridge triggers schedule:run without an always-on scheduler container
- [ ] #5 Health checks succeed against the app readiness endpoint through the load balancer
- [x] #6 Infra docs describe the lean stack, hostname layout, and how to deploy
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Restructure infra/ (no behaviour change): move alarms/application/documents/encryption/operator/policies into src/shared/; split src/environment.ts into src/environments/{settings,environment,production,staging}.ts; add Pulumi.staging.yaml. Preview must show zero changes.
2. Shared components for the lean stack: network.ts (VPC, 2 public subnets, no NAT, SG-based confinement), encryption.ts platform CMK, registry.ts (ECR), database.ts (RDS Postgres 17 t4g.micro, manageMasterUserPassword), cache.ts (Valkey TLS), secrets.ts (Secrets Manager app secret), mail.ts (SES, task-role-only policy), logs.ts, loadBalancer.ts (ACM app+wildcard, ALB idleTimeout 3600, web + reverb target groups), dns.ts (optional hostedZoneId), application.ts execution role, compute.ts (ECS cluster + 5 ARM Fargate services + migrate/schedule task defs), schedule.ts (EventBridge rate(1 minute) -> RunTask).
3. Wiring: widen bootstrap/src/deployPolicy.ts (ec2, elasticloadbalancing, ecs, ecr, rds, elasticache, secretsmanager, acm, route53, ses, logs, events, iam:CreateServiceLinkedRole) keeping DenyModifyingOwnAccess intact; switch the CI bake platform to linux/arm64; parameterise deploy.yml by stack.
4. Tests and docs: stack.production.test.ts (inverted from stack.test.ts) + stack.staging.test.ts in separate files (Pulumi mock runtime is process-global); environments/environment.test.ts; rewrite infra/README.md; record an infra/** rule; supersede decision-2.

Scope: author + build + tests + pulumi preview only. No pulumi up. Subdomain tenancy stays with TASK-34.5.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Landed the infra restructure + the lean compute platform. Author + build + tests only; no `pulumi up`.

**Restructure** — `src/shared/` (components, the docker/base equivalent), `src/environments/{settings,environment,production,staging}.ts` (per-env knobs, data only), `src/testing/harness.ts` (Pulumi mock harness), plus `Pulumi.staging.yaml`. Components read `EnvironmentSettings` and never branch on `environment.name`. Two latent problems surfaced and are fixed: `tsc` never cleaned `bin/`, so pre-move tests kept running against stale JS and passing; and `node --test bin/` executed `bin/index.js` as a test. Both scripts are now `rm -rf bin && tsc` and `node --test "bin/**/*.test.js"`.

**Compute** — network.ts (VPC, 2 public subnets, no NAT, security-group confinement), encryption.ts platform CMK (separate from the OCR key, whose policy grants Transcribe decrypt), registry.ts (ECR, immutable tags), database.ts (Postgres 17 t4g.micro, `manageMasterUserPassword` so no password reaches Pulumi state, `rds.force_ssl`), cache.ts (Valkey, TLS, failover derived from node count), secrets.ts (one JSON secret, per-key `valueFrom`), mail.ts (SES; send policy on the task role only, not the shared managed policy), logs.ts, loadBalancer.ts (ACM app + wildcard, ALB `idleTimeout: 3600`, web `/ready` + reverb `/app/*` target groups), dns.ts (optional hostedZoneId), application.ts execution role, compute.ts (ECS cluster, 5 ARM services + migrate/schedule task defs), schedule.ts (EventBridge `rate(1 minute)` -> RunTask, no retries).

**Wiring** — `bootstrap/src/deployPolicy.ts` widened for ec2/elb/ecs/ecr/rds/elasticache/secretsmanager/acm/route53/ses/logs + `iam:CreateServiceLinkedRole`, `DenyModifyingOwnAccess` intact and asserted. CI bake switched `linux/amd64` -> `linux/arm64` (ARM Fargate; an amd64 image deploys green and dies with exec-format at first task start). `deploy.yml` now takes a `stack` input and runs `npm test` before touching AWS.

**Tests** — 36 green in infra (`stack.production`, `stack.staging`, `stack.dns`, `environment`), 8 green in bootstrap. Separate files because the Pulumi mock runtime is process-global and `index.js` is module-cached.

**Not verified against AWS.** `pulumi preview` reaches the program and fails fast on the missing `appKey` config secret, which is the intended behaviour; the local AWS credentials are invalid (`InvalidClientTokenId`), so no preview against the provider was possible. AC#5 (health checks through the load balancer) is verified-on-deploy.

**Follow-ups deliberately not done here:** ECR push + `ecs update-service` rollout in CI (the repository exists; nothing pushes to it yet), and superseding decision-2 — the `backlog decision` CLI has no content flag and decision files must not be hand-edited.

Closed the image gap that made the services unrunnable.

- `release.yml` (new): build -> push to ECR -> migrate -> roll services. Three `pulumi up` calls by design: (1) ensure infra so ECR exists before anything pushes to it — on a first release this also creates services pointing at a tag that is not in the registry yet, which is self-healing because Pulumi's ECS Service does not wait for steady state; (2) `--target` the migrate task definition alone, so migrations land before any new-code task serves a request — a plain `pulumi up` would update every task definition and roll the services in one step, putting new code in front of an un-migrated schema; (3) roll the services. Between 2 and 3 the workflow runs `aws ecs run-task` and checks the container exit code, because a stopped task is not a successful one.
- Push is `production.output=type=registry`, not the action's `push: true`: a bare push sets `*.output=type=registry` across the graph, sweeping in the `base` target, which carries no tags because it exists only as a named build context.
- `reverbAppKey` reclassified from Pulumi secret to plain `reverbPublicKey` config, exported as a stack output. It is compiled into the JS bundle and served to every visitor, so encrypting it was theatre that also forced the release pipeline to decrypt a value in order to produce a public asset. The image build now reads `VITE_REVERB_HOST` and `REVERB_PUBLIC_KEY` off the stack being released to, which makes a host/key mismatch impossible rather than merely unlikely — an image built against the wrong pair runs perfectly and silently fails to open a WebSocket.
- Added `ecr:GetAuthorizationToken` to the bootstrap deploy policy (account-wide by design, not repository-scopable; without it `docker login` fails at authentication, which reads as a missing repository). Asserted in bootstrap/src/stack.test.ts.
- New outputs `publicSubnetIds` / `taskSecurityGroupId`: a one-shot `run-task` is not a service, so nothing in the stack holds its network configuration and the caller has to supply it or the task lands somewhere that cannot reach the database.
- `deploy.yml` header now states it is infrastructure-only and can never move running code forward.

**Acceptance criteria evidence.** AC#6 checked: infra/README.md documents the lean stack, the hostname layout and the release path. AC#1-#4 are declared by the program and asserted by 36 mock-runtime tests (stack.production / stack.staging / stack.dns / environment), but "provisioned" means deployed and nothing has been applied — local AWS credentials are invalid (InvalidClientTokenId) and this pass was scoped to author + tests. AC#5 needs a live load balancer. Closing #1-#5 requires one `pulumi up` plus a `release.yml` run.
<!-- SECTION:NOTES:END -->
