---
id: TASK-34.2
title: Provision lean AWS compute platform for the app
status: To Do
assignee: []
created_date: '2026-08-11 16:54'
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
- [ ] #6 Infra docs describe the lean stack, hostname layout, and how to deploy
<!-- AC:END -->
