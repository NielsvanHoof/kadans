---
id: TASK-43.3
title: Reduce the Pulumi stack to data plane plus RDS
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-08-22 16:05'
updated_date: '2026-08-28 22:37'
labels: []
dependencies: []
parent_task_id: TASK-43
priority: high
type: chore
ordinal: 99000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Remove the compute plane from infra/ and keep the data plane plus RDS.

Delete: shared/{compute,loadBalancer,edge,schedule,cache,registry,secrets,logs}.ts, createApplicationIdentity and createExecutionIdentity in shared/application.ts, and the ALB/ECS portions of shared/ops.ts and shared/dns.ts.

Keep and adapt:
- shared/network.ts shrinks to VPC + IGW + route table + two subnets + database-sg. Every resourceName() string must stay byte-identical (vpc, igw, public-routes, public-0, public-1, database-sg, db-subnets) because Pulumi keys state by them and a rename reads as delete-then-create, which would destroy the database. database-sg ingress moves from a security-group reference to the Laravel Cloud egress CIDR list.
- shared/database.ts gains publiclyAccessible: true and switches from manageMasterUserPassword to a Pulumi secret config value, because Laravel Cloud needs a static password and cannot resolve a Secrets Manager ARN at task start.
- shared/operator.ts generalises from the Sail-only OCR operator to the application identity, attaching both the app-access managed policy and the SES send policy.

Settings become data per .ai/rules/infra.md: replace computeEnabled with databaseEnabled, add externalComputeIngressCidrs, rename localOperatorAccess to applicationUserAccess, and delete the dead compute knobs.

Laravel Cloud publishes its egress IPs at https://cloud.laravel.com/api/ip and states they are subject to change. A stale allowlist means the application is already down, so drift must fail loudly rather than warn.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 pulumi preview on staging shows the RDS instance, subnet group, VPC and subnets as updates and never as replacements
- [x] #2 The database security group admits 5432 only from the committed Laravel Cloud egress CIDRs
- [x] #3 No ECS, ELB, CloudFront, WAF, ElastiCache, ECR or Secrets Manager resource remains in the program
- [x] #4 The application IAM user carries both the app-access managed policy and the SES send policy
- [ ] #5 A scheduled check fails when the committed egress CIDRs drift from cloud.laravel.com/api/ip
- [x] #6 Stack tests are rewritten against the new surface and infra npm test passes
- [x] #7 The bootstrap deploy policy no longer grants ECS, ELB, CloudFront, ElastiCache, Secrets Manager or log-group permissions, and DenyModifyingOwnAccess is unchanged
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verification pass (board had drifted behind the code — the reduction itself shipped earlier).

AC#2 checked. `databaseIngress()` emits exactly one rule: tcp 5432 from `settings.databaseIngressCidrs`, and throws when `databasePubliclyAccessible` is false or the allowlist is empty. This was previously asserted by NO test, despite being the only control between a publicly-addressable production database and the internet. Added 'the database security group admits 5432 only from Laravel Cloud egress' to stack.production.test.ts, asserting exactly one ingress rule, the port pair, deep equality against LARAVEL_CLOUD_EU_WEST_1_IPV4_CIDRS, and separately that 0.0.0.0/0 is absent (a wildcard alongside real CIDRs would still satisfy 'allowlist present'). Verified it fails: temporarily appending 0.0.0.0/0 to network.ts turned it red, restored, green again.

AC#3 checked. No aws.ecs/lb/alb/cloudfront/wafv2/elasticache/ecr/secretsmanager resource remains outside testing/harness.ts, whose mock branches for those types are now dead but harmless. 'production has no application compute' and 'staging has no application compute' assert it from the stack side.

AC#4 checked. operator.ts attaches both the app-access managed policy (ocr-local-policy) and SES send (ocr-local-mail) to the same user; index.ts passes mail.sendPolicy. This was also untested — 'local mints the Sail OCR operator credentials' only asserted a user and access key existed. Added 'the operator user carries both the app-access and SES send policies', asserting two attachments, both names, and that they land on one identity. Verified it fails by dropping the mail.sendPolicy argument, restored, green again.

AC#6 checked. infra npm test 28/28, 0 skipped (26 before the two tests added here). Confirmed the glob is not hiding files: per-file counts sum exactly to the total. bootstrap npm test 11/11.

AC#7 checked. No ecs:/elasticloadbalancing:/cloudfront:/elasticache:/secretsmanager:/logs: permission remains in infra/bootstrap/src; DenyModifyingOwnAccess is present and asserted by stack.test.ts.

AC#1 NOT checked — needs 'pulumi preview' against staging with valid credentials. Local AWS credentials return InvalidClientTokenId, so no preview can be run from here.

AC#5 NOT checked, and it was the real gap. The drift check existed (laravelCloudEgress.test.ts) but nothing ran it automatically: platform.yml never invokes the infra suite and deploy.yml is workflow_dispatch-only, so between manual infra deploys the guard was dormant. Added .github/workflows/infra-drift.yml — daily cron plus pull_request on apps/platform/infra/**. It also fails when the drift test SKIPS rather than runs: the test calls t.skip() when cloud.laravel.com is unreachable, and a skipped node --test run reports 'pass 0, fail 0' and exits 0, so on a schedule it would have gone green precisely when it checked nothing. Verified that skip-detection empirically with a synthetic skipping test. Leaving AC#5 unchecked until the workflow has actually run once in CI — the logic is verified locally but the wiring is not.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
The Pulumi stack is data plane plus RDS only. Verified in code on 2026-08-29: no `aws.ecs`, `aws.alb`, `aws.lb`, `aws.cloudfront`, `aws.ecr`, `aws.elasticache` or `aws.secretsmanager` resource remains anywhere in `infra/src`, and the `computeEnabled` seam that gated the compute IIFE is gone entirely. 28 infra stack tests pass. Confirmed by the author that AWS now carries data processing only.
<!-- SECTION:FINAL_SUMMARY:END -->
