---
id: TASK-54.5
title: Let a firm set its own retention window
status: To Do
assignee: []
created_date: '2026-09-04 20:37'
labels: []
dependencies:
  - TASK-54.1
parent_task_id: TASK-54
priority: medium
type: feature
ordinal: 117000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Archived clients and dossiers are purged after RETENTION_ARCHIVED_DAYS — 1095 days for every firm on the platform — and the activity log follows the same number. A firm that has agreed a different retention period with its clients cannot honour it.

Retention is the one setting where TASK-54s stricter-only rule does not apply as stated: shorter retention is stricter for privacy, longer is stricter for a statutory bookkeeping obligation. So it needs an explicit two-sided band rather than a one-sided clamp, and the band has to be a deliberate decision rather than a number picked while implementing.

Raised during planning and needing a decision before this ships: the current 1095-day default is roughly three years, which sits below the seven-year Dutch statutory bookkeeping retention. That is a pre-existing product decision, not something this task should quietly change — confirm the intended band with the user first.

PurgeLegalRetentionCommand and PruneActivityLogCommand already iterate every tenant inside TenantContext::runForTenant, so resolving the cutoff per tenant is a move of one expression into an existing closure.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A firm administrator can set the archived-record and activity-log retention windows within an agreed band, or leave them inherited
- [ ] #2 A value outside the band is rejected on save and clamped on read
- [ ] #3 Two firms with different windows purge at different cutoffs within a single command run
- [ ] #4 A firm with no settings row does not abort the sweep for the remaining firms
- [ ] #5 The retention band is confirmed with the user and recorded, given the statutory question above
<!-- AC:END -->
