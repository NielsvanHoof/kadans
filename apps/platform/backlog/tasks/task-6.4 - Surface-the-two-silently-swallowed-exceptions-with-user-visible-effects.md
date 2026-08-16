---
id: TASK-6.4
title: Surface the two silently swallowed exceptions with user-visible effects
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 14:06'
labels: []
dependencies: []
references:
  - app/Reporting/Queries/FetchActivityLogIndexQuery.php
  - app/Dossiers/Console/Commands/SendScheduledDossierRemindersCommand.php
  - app/Tenancy/Actions/SyncTenantRolesAction.php
modified_files:
  - app/Reporting/Queries/FetchActivityLogIndexQuery.php
  - app/Dossiers/Console/Commands/SendScheduledDossierRemindersCommand.php
  - app/Tenancy/Actions/SyncTenantRolesAction.php
  - tests/Feature/Reporting/ActivityLogTest.php
  - tests/Feature/Dossiers/DossierFollowUpWorkflowTest.php
parent_task_id: TASK-6
priority: medium
type: bug
ordinal: 25000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two catches drop errors that the user needs to see:

The activity log index query catches Throwable around date parsing for the created_from and created_to filters and returns, so an unparseable date silently drops the filter. The UI keeps showing the filter chip while returning unfiltered results - the user believes they are looking at a narrowed list and they are not.

The scheduled dossier reminders command catches ValidationException and force-writes a null next reminder timestamp, which permanently disables scheduled reminders for that dossier, with no log and no signal anywhere in the UI. Nobody finds out until a client is never chased.

A third, lower-stakes case: the tenant role sync action continues past a Throwable without reporting it, so permission-sync drift goes unnoticed.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 An unparseable date filter produces a validation error the user can see rather than silently returning unfiltered results
- [x] #2 A dossier whose reminders are disabled by a validation failure is logged and the state is visible on the dossier rather than silent
- [x] #3 The role sync action reports exceptions it continues past
- [x] #4 Tests cover the bad-date filter and the reminder-disabling path
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. FetchActivityLogIndexQuery: on unparseable created_from/created_to throw ValidationException with filter field message (user-visible) instead of silent return.
2. SendScheduledDossierRemindersCommand: on ValidationException log warning with dossier_id + errors; clear next_reminder_at (and reminder_interval_days for visible off state).
3. SyncTenantRolesAction prune: report($e) before continue.
4. Tests for bad date filter + reminder disable path; assert report on prune if practical.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Surfaced three silently swallowed failures: bad activity-log date filters, reminder auto-disable, and role-sync prune errors.

## Changes
- FetchActivityLogIndexQuery throws ValidationException for unparseable created_from/created_to.
- SendScheduledDossierRemindersCommand logs a warning and clears next_reminder_at + reminder_interval_days on ValidationException.
- SyncTenantRolesAction reports exceptions when pruning permissions.

## Tests
ActivityLogTest bad-date filter; DossierFollowUpWorkflowTest reminder-disable path.
<!-- SECTION:FINAL_SUMMARY:END -->
