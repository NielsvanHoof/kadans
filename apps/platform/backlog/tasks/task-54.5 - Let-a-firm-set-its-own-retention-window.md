---
id: TASK-54.5
title: Let a firm set its own retention window
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-04 20:37'
updated_date: '2026-09-06 11:35'
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
- [x] #1 A firm administrator can set the archived-record and activity-log retention windows within an agreed band, or leave them inherited
- [x] #2 A value outside the band is rejected on save and clamped on read
- [x] #3 Two firms with different windows purge at different cutoffs within a single command run
- [x] #4 A firm with no settings row does not abort the sweep for the remaining firms
- [x] #5 The retention band is confirmed with the user and recorded, given the statutory question above
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Band decision taken with the user: 365..3650 days (1-10 years), already in config/retention.php from 54.1, and the platform default moves 1095 -> 2555 for archived records. Raising a retention default only defers deletion, so no existing data is purged earlier than it would have been. Both literals need changing: activity_log_days falls back to env("RETENTION_ARCHIVED_DAYS", 1095), so the inner default is a second copy of the number.
2. PurgeLegalRetentionCommand: move the cutoff out of handle() and into the existing runForTenant closure so each firm is purged against its own window. The aggregate log line currently reports one cutoff, which stops being a single value - report tenants processed instead.
3. PruneActivityLogCommand: same move, same reasoning.
4. docs/security.md: the retention table states 1095 days for both rows and is the record a firm would be shown. Update it, and say that the window is now per firm within a stated band.
5. Tests: two firms with different windows purge at different cutoffs inside one command run; a firm with no settings row does not abort the sweep for the others; a value outside the band is rejected on save and clamped on read.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Band decided with the user: 365-3650 days, and the platform default raised 1095 -> 2555 (three years -> seven), matching the retention an accounting firm holding client financial records is normally subject to. Raising a retention default only defers deletion, so no existing record is purged earlier than it would have been. Both literals needed changing - activity_log_days falls back to env("RETENTION_ARCHIVED_DAYS", 1095), so the old number was written twice.

The band stays wide rather than being floored at seven years: not every record a firm holds falls under the obligation, and storage limitation pushes the other way. docs/security.md now states the default, the band, and that the window is per firm - that table is the record a firm would actually be shown.

Both sweeps resolve their cutoff inside the existing runForTenant loop, so one run works against as many cutoffs as there are firms. The aggregate log line in PurgeLegalRetentionCommand used to report a single cutoff, which stopped being a meaningful value; it reports tenants processed instead.

Two things the tests caught that I would not have found by reading:

1. The platform clamp I added in 54.4 was wrong for this setting. For the portal windows the hard limits describe what the value may physically be, so clamping a misconfigured platform value is right. For retention the limits describe the range offered to a FIRM, and clamping an operators short staging window up to the 365-day floor meant nothing was ever pruned there - three existing PruneActivityLogTest cases failed on it. platform() now clamps only for the directional bounds, and both halves of that distinction are pinned by tests.

2. My first draft of the two-firm test passed for the wrong reason. Dossier and Activity are tenant-scoped, so a plain query in an assertion only sees whichever firm happens to be current and reports every other firms rows as gone. The assertions now go through Dossier::queryWithoutTenantScope() and Activity::queryAcrossTenants(), which is what made the settings-less-tenant case fail honestly and then pass for the right reason.

Validation: pest tests/Feature/Retention/FirmRetentionWindowTest.php => 6 passed. Retention/Portal/Tenancy/Unit/Architecture/Platform => 764 passed. ClientPortal/Dossiers/Audit/Clients/Localization => 280 passed. pint and vp check clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A firm sets its own retention window, and the platform default now matches the obligation most customers are under.

The band is 365-3650 days and the default moved from three years to seven, decided with the user; raising it only defers deletion, so nothing is purged earlier than before. retention:purge-legal and retention:prune-activity-log resolve their cutoff inside the tenant loop they already had, so one sweep runs against every firms own window. docs/security.md records the default, the band and the per-firm behaviour.

Verified with 6 tests: two firms purged at different cutoffs in a single run, an unset firm falling back to the platform window, a firm with no settings row not aborting the sweep for the others, activity history pruned on each firms own window, and both ends of the band refused on save. Assertions go through the cross-tenant query helpers - the first draft passed for the wrong reason because tenant scoping hid the other firms rows.

Also corrected a clamp from 54.4 that was right for the portal windows and wrong here: an operator short retention window was being raised to the firm floor, so nothing was pruned. 764 tests across the retention and tenancy suites, 280 across the rest, pint and vp check clean.
<!-- SECTION:FINAL_SUMMARY:END -->
