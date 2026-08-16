---
id: TASK-34.4
title: 'Add budgets, critical alarms, and RDS cross-region snapshot DR'
status: To Do
assignee: []
created_date: '2026-08-11 16:54'
updated_date: '2026-08-11 16:55'
labels:
  - infra
  - aws
  - ops
milestone: m-0
dependencies:
  - TASK-34.2
references:
  - >-
    .cursor/projects/Users-nielsvanhoof-Documents-projects-qompose-apps-platform/canvases/lean-security-extras.canvas.tsx
documentation:
  - infra/README.md
parent_task_id: TASK-34
priority: medium
type: feature
ordinal: 6000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Ops extras from the Lean + security extras canvas so production is vacation-safe without buying Aurora.

Covers AWS Budgets, critical email/SMS alarms (ALB 5xx, ECS unhealthy, RDS failover, DLQ depth), and cheap RDS snapshot + cross-region copy DR.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 AWS Budget alerts fire when spend crosses the configured threshold
- [ ] #2 Critical alarms cover ALB 5xx, unhealthy ECS targets, RDS failover, and DLQ depth with email/SMS delivery
- [ ] #3 RDS automated snapshots are retained and copied cross-region for cheap DR
- [ ] #4 Alarm and budget runbooks are documented for on-call
- [ ] #5 Infra docs list what is monitored and how to acknowledge/escalate
<!-- AC:END -->
