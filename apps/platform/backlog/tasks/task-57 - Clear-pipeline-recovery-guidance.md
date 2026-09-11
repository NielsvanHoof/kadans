---
id: TASK-57
title: Clear pipeline recovery guidance
status: Done
assignee:
  - '@claude'
created_date: '2026-09-11 10:11'
updated_date: '2026-09-11 10:35'
labels: []
dependencies: []
references:
  - resources/js/pages/workspaces/pipelines/show.tsx
  - resources/js/features/pipelines/show/pipeline-show-diagnostics.tsx
priority: medium
type: enhancement
ordinal: 121000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The pipeline show page already has Retry, Cancel, next_retry_at, and a Diagnostics card, but they are mixed: next-retry timing, the localised failure sentence, machine error codes, and provider IDs sit in one card while Retry lives in the header with no explanation of whether to wait or click.

Staff need to distinguish "retry scheduled," "staff action needed," and technical diagnostics. Recovery controls already exist — this is guidance and layout, not new retry machinery.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Failed runs with next_retry_at show a "Retry scheduled" banner with the next try time and guidance that Retry can start now
- [x] #2 Failed runs past the auto-retry budget, stuck runs, and cancelled runs show a staff-action banner telling them to use Retry
- [x] #3 Pending, running, and completed runs show no recovery banner
- [x] #4 Diagnostics keeps provider IDs, machine error_code, raw error_message, and metadata — not next_retry_at or the localised failure sentence
- [x] #5 The show page reloads over Echo while a retry is scheduled (next_retry_at set), not only while pending/running
- [x] #6 EN and NL copy stay aligned via lang:organize; frontend tests cover the three recovery kinds
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add pipelineRecoveryKind() derived from status + next_retry_at.
2. Add PipelineRecoveryGuidance banner between header and summary on the show page.
3. Strip next_retry_at and localised sentence from PipelineShowDiagnostics; keep technical fields.
4. Enable Echo reload when next_retry_at is set.
5. Add EN/NL copy via lang:organize and frontend unit/component tests.
6. Run pipelines frontend tests.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Added pipelineRecoveryKind / pipelineNeedsLiveReload helpers, PipelineRecoveryGuidance banner, and PipelineTechnicalError for the diagnostics sidebar. Removed next_retry_at and the localised sentence from Diagnostics. EN/NL keys in app/Pipelines/lang; orphaned "Next retry" / old diagnostics description removed.

Validation: vp test resources/js/features/pipelines/tests => 51 passed. Focused recovery/status/diagnostics tests => 31 passed. LanguageCoverageTest => 7 passed. lang:organize --check up to date. Interactive browser login was blocked by the environment, so UI behaviour was verified via component tests covering scheduled, staff-action, and completed states.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Split pipeline show-page recovery guidance from technical diagnostics. Failed runs with next_retry_at show "Retry scheduled"; capped/stuck/cancelled runs show staff-action copy; Diagnostics keeps only provider IDs and raw errors. Echo reload stays on while a retry is booked. Verified with 51 pipelines frontend tests, LanguageCoverageTest, and lang:organize --check.
<!-- SECTION:FINAL_SUMMARY:END -->
