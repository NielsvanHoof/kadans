---
id: decision-8
title: Own application compute and deployment in Pulumi
date: '2026-08-18 13:31'
status: accepted
---
## Context

decision-2 accepted shipping v1.0 with no application compute in Pulumi, with the
reopen trigger "before the first real client document enters the system". At that
point `infra/` provisioned only S3, SNS, SQS and KMS; CI built an immutable OCI
image with SBOM and provenance and pushed it to no registry, and `deploy.yml` was
a manual Pulumi run for the OCR stack alone.

That decision also blocked decision-3: the ECS task role existed in policy form
but could not replace the local IAM user's static credentials while no compute
existed to carry it.

## Decision

Provision the lean AWS platform in Pulumi and own the deploy path.

`infra/` is restructured to mirror `docker/` — `src/shared/` holds the components,
`src/environments/` holds per-environment knobs as data, one stack per
environment. It provisions a VPC on public subnets with no NAT (confinement moves
to security groups), an ALB, ECS Fargate on ARM running one image as several
entrypoint roles, RDS Postgres, Valkey, ECR, Secrets Manager, SES, and EventBridge
→ RunTask for the scheduler. `release.yml` builds, pushes to ECR, migrates, and
rolls the services.

Supersedes decision-2. Implemented by TASK-34.2, extended by TASK-34.3 (CloudFront
and WAF) and TASK-34.4 (budgets, alarms, cross-region backup DR).

## Consequences

- **This is a decision about the code, not about a running system.** Nothing has
  been applied: the stack is authored, built and covered by tests, but no
  `pulumi up` has run and no `pulumi preview` has reached the provider. Until it
  does, the acceptance criteria that say "provisioned" are unmet.
- Unblocks decision-3 for the deployed path — the container gets credentials from
  the ECS agent and no keys exist in production. The developer laptop still uses a
  long-lived key, so decision-3 narrows rather than closes.
- Couples CI to the runtime: task definitions are ARM64, so `docker buildx bake`
  must produce `linux/arm64`. An amd64 image deploys cleanly and then fails with
  an exec-format error at first task start.
- Couples the task environment to `ProductionConfigurationValidator`. A stack that
  gets one of those keys wrong deploys green and then refuses every request.
- No NAT means tasks carry public IPs and egress is unfiltered. If the platform
  ever holds something that must not be able to phone out, that trade needs
  revisiting.
- Roughly €95–120/month for production, plus €55–70 if staging is brought up.
- **Reopen trigger:** the no-NAT decision, if egress control becomes a
  requirement; and the EventBridge scheduler, if ~43,000 monthly Fargate task
  starts prove more expensive than an always-on scheduler container.
