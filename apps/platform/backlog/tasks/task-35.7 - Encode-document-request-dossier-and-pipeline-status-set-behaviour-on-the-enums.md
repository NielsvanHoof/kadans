---
id: TASK-35.7
title: >-
  Encode document-request, dossier, and pipeline status-set behaviour on the
  enums
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-13 11:33'
updated_date: '2026-08-14 08:04'
labels:
  - audit
dependencies: []
references:
  - app/Pipelines/Enums/PipelineStatus.php
  - app/Dossiers/Queries/FetchWorkspaceDashboardQuery.php
  - app/DocumentRequests/Enums/DocumentRequestStatus.php
  - app/Dossiers/Enums/DossierStatus.php
  - app/Dossiers/Enums/DocumentRequestStatus.php
  - tests/Unit/DocumentRequestStatusTest.php
  - tests/Unit/DossierStatusTest.php
  - tests/Unit/PipelineStatusTest.php
  - .ai/rules/requests-enums-policies.md
parent_task_id: TASK-35
priority: medium
type: enhancement
ordinal: 7000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Status sets are hand-written across files. [DocumentRequestStatus::Pending, Rejected] appears eight times across six files, including four times in one dashboard query method, which also repeats DossierStatus::Completed three times. DossierStatus::Completed as "locked" appears in about ten sites; PipelineStatus has the same shape for retryable/settled. Adding a status (Expired is plausible) means finding every site; miss the dashboard and a compliance tile silently under-counts.

Put behaviour on the enums, exactly as PipelineStatus::isTerminal() already does — consistency with an existing decision, not a new pattern. Do not introduce registries or shared abstractions. TranscriptionStatus was already moved to AudioProcessing in TASK-9.1; skip that part of the original P2 note.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Awaiting-client document-request statuses are named in one place on DocumentRequestStatus and used by former call sites
- [x] #2 Locked dossier status is named on DossierStatus and used by former "Completed means locked" sites
- [x] #3 Retryable and settled pipeline statuses are named on PipelineStatus, consistent with the existing isTerminal() method
- [x] #4 Adding a new status does not require hunting duplicated arrays across dashboard and index queries
- [x] #5 TranscriptionStatus is not moved; it already lives in AudioProcessing (TASK-9.1)
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Put status-set behaviour on the three enums, matching PipelineStatus::isTerminal() (exhaustive instance match + static *Values() derived from it for SQL). No registries, no shared trait, do not move TranscriptionStatus.

1. DocumentRequestStatus: isAwaitingClient() Pending+Rejected; awaitingClient() / awaitingClientValues() derived from cases(). Replace duplicated [Pending, Rejected] arrays in dashboard, index queue, reminders command, outstanding-items query, staff row mapper, reminder draft, portal canSubmit base. Leave review decisions [Accepted, Rejected] and Pending-only creates alone.
2. DossierStatus: isLocked() Completed (exhaustive); lockedValues() for SQL. Replace === Completed / whereNot Completed at lock/open-dossier sites (policies, dashboard, index, builder, reminders, transitions guards). Leave complete() assignment as Completed.
3. PipelineStatus: isRetryable() Failed+Stuck; isSettled() Completed+Cancelled; keep isTerminal(). Replace Failed+Stuck in Policy::retry and Transitions::retry; replace Completed+Cancelled in ResolveProviderPipelineForSubjectAction, stuck(), ensureCanRun(). Leave Failed-only scheduled retry and Stuck-only health check.
4. Unit tests (pure PHP) with covers() datasets for every case. Existing dashboard/policy/transition Feature tests stay as behaviour coverage.
5. record-rule: status sets live on the enum as isX() + *Values().
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Awaiting-client, locked, retryable, and settled are exhaustive match methods. SQL lists are derived from cases() so a new matching case is picked up without hunting dashboard/index arrays.

Did not widen Failed-only RetryDuePipelinesCommand or Stuck-only health checks to isRetryable(). Did not replace review [Accepted, Rejected].

TranscriptionStatus remains in DocumentProcessing; this subtask skipped the move as specified.

Boost record-rule MCP was unavailable; the durable rule was added to .ai/rules/requests-enums-policies.md.

Verification: vendor/bin/sail artisan test --compact on the three unit files plus Dashboard, DossierPolicy, DocumentRequestTransitions, PipelinePolicy, PipelineTransitions, DossierReminderDraft, DossierIndexQuery (170 passed). PHPStan 0 errors on changed PHP.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Status sets now live on the enums, matching PipelineStatus::isTerminal(): exhaustive isX() plus *Values() derived from cases() for SQL.

DocumentRequestStatus::isAwaitingClient() names Pending+Rejected. Dashboard, dossier index queues, scheduled reminders, outstanding-items, staff row mapper, reminder draft, and portal/staff canSubmit use that instead of duplicated arrays. Review decisions stay [Accepted, Rejected].

DossierStatus::isLocked() names Completed. Policies, dashboard/index/builder queries, reminder send/guards, and transition guards use isLocked()/lockedValues(). complete() still assigns Completed.

PipelineStatus gained isRetryable() (Failed+Stuck) and isSettled() (Completed+Cancelled). Policy::retry, Transitions::retry, stuck(), ensureCanRun(), and ResolveProviderPipelineForSubjectAction use those. Failed-only scheduled retry and Stuck-only health checks were left alone. isTerminal() is unchanged.

TranscriptionStatus was not moved (still app/DocumentProcessing/Enums/TranscriptionStatus.php).

Tests: unit datasets covering every case (DocumentRequestStatusTest, DossierStatusTest, PipelineStatusTest). Feature: DashboardTest, DossierPolicyTest, DocumentRequestTransitionsTest, PipelinePolicyTest, PipelineTransitionsTest, DossierReminderDraftTest, DossierIndexQueryTest — 170 passing. PHPStan clean on changed files. Rule recorded in .ai/rules/requests-enums-policies.md (Boost record-rule MCP was down).
<!-- SECTION:FINAL_SUMMARY:END -->
