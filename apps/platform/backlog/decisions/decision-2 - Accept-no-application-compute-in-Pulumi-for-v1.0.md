---
id: decision-2
title: Accept no application compute in Pulumi for v1.0
date: '2026-08-11 10:05'
status: accepted
---
## Context

`infra/` provisions S3, SNS, SQS, and KMS (plus related supporting resources). There is
no ECS service, queue worker, scheduler, or application deploy step in the stack. CI
builds an immutable OCI image with SBOM/provenance but pushes it to no registry
(GitHub Actions artifact, 7-day retention). `deploy.yml` is a manual-dispatch Pulumi
run for the OCR stack only.

## Decision

Accept that application compute and app deploy are out of v1.0 scope. The product can be
code-complete without a production app runtime in Pulumi yet.

## Consequences

- **Reopen trigger:** before the first real client document enters the system — shipping
  production traffic without owned compute/deploy is not acceptable past that point.
- Blocks eliminating static local IAM credentials (decision-3): the ECS task role path
  already exists in policy form but cannot replace local keys until compute exists.
