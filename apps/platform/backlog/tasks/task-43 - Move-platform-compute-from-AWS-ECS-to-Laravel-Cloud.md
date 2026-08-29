---
id: TASK-43
title: Move platform compute from AWS ECS to Laravel Cloud
status: Done
assignee: []
created_date: '2026-08-22 16:04'
updated_date: '2026-08-28 22:38'
labels: []
dependencies: []
priority: high
type: feature
ordinal: 96000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Hand the compute plane (VPC, ALB, CloudFront/WAF, ECS Fargate, ElastiCache, ECR, Secrets Manager, CloudWatch log groups) to Laravel Cloud, while keeping the data plane (S3, SNS, SQS, EventBridge, Textract, Transcribe, Bedrock, GuardDuty, CloudTrail, SES, KMS) and RDS Postgres in our own AWS account.

Motivation: the platform owns a large amount of infrastructure for a single Laravel application. Releasing currently requires three `pulumi up` invocations around a `docker buildx bake` and an `aws ecs run-task` migration gate. Laravel Cloud collapses that into a git push while keeping every AWS integration intact.

The seam already exists: `EnvironmentSettings.computeEnabled` gates an IIFE in `infra/src/index.ts`, and the `local` stack (computeEnabled: false) proves the document/OCR/transcription plane needs no VPC.

Decisions taken:
1. RDS stays on AWS, made publicly addressable and narrowed to Laravel Cloud egress IPs.
2. Uploads move to presigned direct-to-S3 first (Laravel Cloud exposes no php.ini and its Cloudflare edge caps bodies at 100 MB).
3. The ECS/ALB/CloudFront Pulumi modules and deployed Docker images are deleted, not left dormant.
4. Staging first, then production.

Supersedes the AWS compute build-out in TASK-34.2 and TASK-34.3.

Correction (2026-08-29): this previously also claimed to supersede TASK-34.4. It does not, and TASK-34.4's own reconciliation note of 2026-08-25 says so — budgets, alarms and DR are data-plane concerns that all survived the move and still exist in `infra/src/shared/ops.ts` and `alarms.ts`. The claim is removed here so the contradiction is fixed at its source rather than only refuted downstream.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Production and staging serve traffic from Laravel Cloud with no ECS, ALB or CloudFront resources remaining
- [ ] #2 S3, SQS, SNS, EventBridge, Textract, Transcribe, Bedrock, GuardDuty, CloudTrail, SES and KMS continue to work unchanged
- [ ] #3 RDS Postgres remains in our AWS account and is reachable only from Laravel Cloud egress IPs over TLS
- [ ] #4 ProductionConfigurationValidator passes on both deployed environments
- [ ] #5 Document, portal and audio uploads work end to end without relying on php.ini limits
<!-- AC:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: claude
created: 2026-08-28 22:19
---
Backlog audit. Two things about this task are out of date, neither of them the work itself.

**The supersedes line was wrong** and is now corrected in the description. TASK-34.4 was never superseded; its own note said so four days ago and this description kept contradicting it.

**Status is inconsistent with the subtasks.** This sits at To Do while 43.5 is Done and 43.3 and 43.4 are In Progress. Verified in code today: no `aws.ecs`, `aws.alb`, `aws.lb`, `aws.cloudfront`, `aws.ecr`, `aws.elasticache` or `aws.secretsmanager` resource remains anywhere in `infra/src`, and the `computeEnabled` seam this description refers to is gone entirely — so AC#1's Pulumi half is met. What is not yet evidenced from here is the deployed half of #1 through #5, which needs someone with credentials to confirm against the running environments.

Leaving the status alone rather than guessing at it — moving a parent to Done while its subtasks are open would trade one inconsistency for another.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Compute moved from AWS ECS to Laravel Cloud; AWS keeps the data plane and RDS. Confirmed by the author on 2026-08-29 that the Cloud environments are set up and AWS is reduced to data processing.

AC#1 checked on two pieces of evidence: the Pulumi half verified in code (no ECS, ALB, CloudFront, ECR, ElastiCache or Secrets Manager resource anywhere in `infra/src`, and the `computeEnabled` seam removed entirely, with 28 infra tests passing), and the serving half on the author's confirmation.

AC#2 through #5 are left unchecked deliberately. Each is an observation against the deployed environments — the AWS integrations still working end to end, RDS reachable only from Laravel Cloud egress over TLS, ProductionConfigurationValidator passing at boot, and uploads working without php.ini limits. AWS credentials here return an expired token, so none could be evidenced from the repository, and ticking them on inference would make this record exactly as trustworthy as the "supersedes TASK-34.4" line that had to be corrected.

Subtasks: 43.1, 43.2, 43.3, 43.4 and 43.5 are all Done.
<!-- SECTION:FINAL_SUMMARY:END -->
