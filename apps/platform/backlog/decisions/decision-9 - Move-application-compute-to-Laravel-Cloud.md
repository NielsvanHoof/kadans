---
id: decision-9
title: Move application compute to Laravel Cloud
date: '2026-08-25 21:05'
status: accepted
---
## Context

decision-8 authored the whole compute plane in Pulumi — VPC, ALB, ECS Fargate on
ARM, RDS, Valkey, ECR, Secrets Manager, EventBridge → RunTask — and `release.yml`
built an image, pushed it to ECR, ran a migration task and rolled the services.
Its own first consequence was the honest one: *"This is a decision about the code,
not about a running system. Nothing has been applied."*

That never changed. The stack was authored, tested and never deployed, and the
cost of keeping it was visible before it ever earned anything: releasing meant
three `pulumi up` invocations around a `docker buildx bake` and an
`aws ecs run-task` migration gate, for a single Laravel application.

The seam that made the move cheap already existed. `EnvironmentSettings.computeEnabled`
gated the compute IIFE in `infra/src/index.ts`, and the `local` stack ran with it
off — proving the document, OCR and transcription plane needs no VPC of ours.

## Decision

Hand the compute plane to Laravel Cloud. Keep the data plane and RDS in our own
AWS account.

Laravel Cloud owns the app cluster (Octane + scheduler), the worker cluster
(`queue:work` over `default,ocr,transcription,dossier-export` plus the
`textract:consume` and `transcribe:consume` SQS daemons), Valkey for
cache/session/queue, and managed Reverb. Deploys are a git push; `php artisan
migrate --force` runs as the deploy command, before traffic shifts, so the
migrate-before-roll guarantee survives.

We keep S3, SNS, SQS, EventBridge, Textract, Transcribe, Bedrock, GuardDuty,
CloudTrail, SES, KMS and RDS Postgres. `infra/` is reduced to that data plane plus
a VPC whose only purpose is hosting a publicly-addressable RDS instance, confined
by a security group allowlisting the committed Laravel Cloud egress CIDRs.

The ECS/ALB/CloudFront/ElastiCache/ECR/Secrets Manager modules and the deployed
Docker images are **deleted, not left dormant** — a dormant module is a module
nobody tests and everybody half-trusts.

Supersedes decision-8. Implemented by TASK-43.1 through TASK-43.5.

## Consequences

- **Uploads had to move first.** Laravel Cloud exposes no `php.ini` and its
  Cloudflare edge caps request bodies at 100 MB, so `post_max_size` stopped being
  a lever we own. TASK-43.1 moved staff, portal and audio uploads to presigned
  direct-to-S3 with an encrypted upload ticket, and size/MIME are now verified
  server-side against the stored object rather than trusted from the client.
- **The database is publicly addressable.** There are no private subnets to hide
  it in, because Laravel Cloud is not in our VPC. `network.ts` refuses to build a
  data-plane stack without `databasePubliclyAccessible` and a non-empty
  `databaseIngressCidrs`, so the allowlist cannot be silently emptied into an open
  database.
- **That allowlist is a live dependency on someone else's IP range.** If Laravel
  Cloud rotates its published egress CIDRs and
  `infra/src/shared/laravelCloudEgress.ts` does not follow, production loses its
  database. The drift check that guards this must run on a schedule, not only on a
  manual infra deploy.
- **Credential posture regressed.** Laravel Cloud is not something AWS can hand a
  role to, so the deployed application authenticates with a long-lived IAM access
  key where an ECS task role would have carried temporary credentials. This is the
  one place the move is strictly worse. See decision-10.
- Resource logical names in `network.ts` and `operator.ts` are now load-bearing in
  a new way: Pulumi keys state by them, and a rename reads as delete-then-create —
  which, for the database, means destroying it.
- Releasing is a git push. The three-invocation release path, `release.yml`,
  `docker-bake.hcl` and `docker/{base,staging,production}` are gone; `docker/8.5`,
  `docker/pgsql`, `docker/floci` and `compose.yaml` still provide local Sail.
- **Reopen trigger:** Laravel Cloud Private Cloud, if it becomes available at a
  price that makes sense — it is the only option that would restore role-based
  credentials and put compute back inside a VPC we control.
