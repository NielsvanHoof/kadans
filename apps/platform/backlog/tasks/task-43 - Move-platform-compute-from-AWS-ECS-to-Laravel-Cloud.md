---
id: TASK-43
title: Move platform compute from AWS ECS to Laravel Cloud
status: To Do
assignee: []
created_date: '2026-08-22 16:04'
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

Supersedes the AWS compute build-out in TASK-34.2, TASK-34.3 and TASK-34.4.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Production and staging serve traffic from Laravel Cloud with no ECS, ALB or CloudFront resources remaining
- [ ] #2 S3, SQS, SNS, EventBridge, Textract, Transcribe, Bedrock, GuardDuty, CloudTrail, SES and KMS continue to work unchanged
- [ ] #3 RDS Postgres remains in our AWS account and is reachable only from Laravel Cloud egress IPs over TLS
- [ ] #4 ProductionConfigurationValidator passes on both deployed environments
- [ ] #5 Document, portal and audio uploads work end to end without relying on php.ini limits
<!-- AC:END -->
