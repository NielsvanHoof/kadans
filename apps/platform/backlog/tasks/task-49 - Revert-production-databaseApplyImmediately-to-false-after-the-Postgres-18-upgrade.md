---
id: TASK-49
title: >-
  Revert production databaseApplyImmediately to false after the Postgres 18
  upgrade
status: To Do
assignee: []
created_date: '2026-08-28 21:43'
labels: []
milestone: m-0
dependencies: []
references:
  - infra/src/environments/production.ts
  - infra/src/shared/database.ts
priority: high
type: chore
ordinal: 107000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`databaseApplyImmediately` was set to `true` for production so the Postgres 17 -> 18 major version upgrade could land. It is meant to be temporary and is commented as such in `infra/src/environments/production.ts`.

Why it had to change: a major version bump replaces the RDS parameter group, because `family` is immutable. While `applyImmediately` is false the swap stays pending, the instance remains a member of the outgoing group, and the delete deadlocks with `InvalidDBParameterGroupState`. This deadlocked the local stack twice before the setting was introduced.

Why it must go back: with it true, every future instance modification interrupts production the moment it is applied, instead of waiting for the `Mon:03:15` maintenance window. That is acceptable while there are no live customers and unacceptable once there are. The longer it stays true, the more likely someone ships an unrelated database change and takes an unscheduled outage with it.

Do this as soon as production reports engine version 18 — it is not work that should wait for a convenient moment.

Note for whoever picks this up: reverting to `false` does not re-introduce the deadlock. That only bites on a major version bump, and the next one (18 -> 19) will need the same temporary flip, or a manual instance reboot after the apply queues the change.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Production RDS reports engine version 18 before anything is reverted
- [ ] #2 databaseApplyImmediately is false again in infra/src/environments/production.ts, and the TEMPORARY comment block is removed
- [ ] #3 The infra stack tests pass and a pulumi preview against production shows no unintended instance modification
- [ ] #4 The upgrade path for a future major version bump is written down, so the next person does not rediscover the deadlock
<!-- AC:END -->
