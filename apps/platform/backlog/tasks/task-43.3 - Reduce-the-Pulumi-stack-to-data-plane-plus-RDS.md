---
id: TASK-43.3
title: Reduce the Pulumi stack to data plane plus RDS
status: To Do
assignee: []
created_date: '2026-08-22 16:05'
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
- [ ] #2 The database security group admits 5432 only from the committed Laravel Cloud egress CIDRs
- [ ] #3 No ECS, ELB, CloudFront, WAF, ElastiCache, ECR or Secrets Manager resource remains in the program
- [ ] #4 The application IAM user carries both the app-access managed policy and the SES send policy
- [ ] #5 A scheduled check fails when the committed egress CIDRs drift from cloud.laravel.com/api/ip
- [ ] #6 Stack tests are rewritten against the new surface and infra npm test passes
- [ ] #7 The bootstrap deploy policy no longer grants ECS, ELB, CloudFront, ElastiCache, Secrets Manager or log-group permissions, and DenyModifyingOwnAccess is unchanged
<!-- AC:END -->
