---
id: TASK-34.4
title: 'Add budgets, critical alarms, and RDS cross-region snapshot DR'
status: In Progress
assignee:
  - '@NielsvanHoof'
created_date: '2026-08-11 16:54'
updated_date: '2026-08-18 13:16'
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
- [x] #4 Alarm and budget runbooks are documented for on-call
- [x] #5 Infra docs list what is monitored and how to acknowledge/escalate
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. ops.ts: one SNS alerts topic (email always, SMS optional) with a policy naming cloudwatch/events/budgets/rds; consolidate the OCR DLQ alarms and the malware findings rule onto it.
2. AWS Budget with an ACTUAL 80% and a FORECASTED 100% notification.
3. Platform alarms: ALB 5xx, unhealthy web/reverb targets, RDS free storage, stopped queue worker, plus an RDS event subscription for failover.
4. Cross-region RDS automated backup replication to eu-central-1 via a destination-region provider.
5. Widen the bootstrap deploy policy for budgets and RDS event/backup APIs.
6. Tests plus a runbook table in infra/README.md covering what each alarm means and the first move.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Ops layer implemented in ops.ts, plus a consolidation the earlier tasks had left pending.

**One topic, not several.** `createOcrAlarms` used to mint its own `ocr-alarms` topic, and TASK-34.1 targeted that same topic for malware findings — a name that had stopped describing its contents. All of it now publishes to one `alerts` topic created in ops.ts: on-call needs a single place to subscribe, and a topic per subsystem is how an alarm ends up with no subscribers. Its policy names cloudwatch, events, budgets and rds, because only CloudWatch may publish by default and the others would otherwise match, fire, and silently fail to deliver.

**The alarm set is deliberately small.** Every alarm that fires without needing action trains someone to ignore the next one. Two design points worth keeping: `HTTPCode_ELB_5XX_Count` rather than target 5xx (the former means the load balancer could not reach a healthy target; the latter is the app erroring, which is a far noisier conversation), and `treatMissingData` is not uniform — `notBreaching` on the 5xx alarm because no data means no traffic, `breaching` on `UnHealthyHostCount` because no data means no target is reporting at all.

**RDS failover is an event, not a metric.** The point of paying for Multi-AZ is that the application survives a failover, so it is invisible in CPU and connection graphs. Handled with an `rds.EventSubscription` on failover/failure/maintenance/deletion/low storage.

**Budget has both an actual and a forecast trigger.** They answer different questions: 80% ACTUAL says the money is already spent; 100% FORECASTED fires early in a month whose run rate has changed — a runaway scheduler, a retry loop, a service that scaled and never came back down — while there is still time to act.

**The one region exception.** The user's direction is eu-west-1 for everything AWS, and that holds — except the automated-backup replica, which defaults to eu-central-1. A backup in the same region as its database is a second copy of one failure domain, not disaster recovery. eu-central-1 keeps the data inside the EU; `disasterRecoveryRegion` moves it and `crossRegionSnapshotCopy: false` turns it off (staging). The replication resource is created in the destination region so it carries its own provider, and the KMS key is deliberately left unset because keys are regional and the source key cannot encrypt the replica — an unreadable backup is not a backup. (CloudFront's certificate and WebACL are also forced into us-east-1 by AWS, but hold no data.)

**Acceptance criteria evidence.** #4 and #5 checked: infra/README.md has a "What is monitored, and what to do about it" section with a row per alarm giving what it means and the first move, plus how acknowledging works (alarms carry okActions, so recovery arrives on the same topic and nothing needs muting) and how to escalate (add a subscriber to the one topic). A Disaster recovery section covers the replica, the region choice and the restore path — explicitly noting the recovery time is unmeasured because it has not been rehearsed. #1, #2 and #3 are declared and asserted by 59 infra tests but need a deploy to be true.

Verified: 59 infra tests, 8 bootstrap tests. No application code changed.
<!-- SECTION:NOTES:END -->
