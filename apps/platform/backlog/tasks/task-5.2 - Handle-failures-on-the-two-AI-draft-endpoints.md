---
id: TASK-5.2
title: Handle failures on the two AI draft endpoints
status: Done
assignee:
  - cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 12:42'
labels: []
dependencies: []
references:
  - resources/js/features/questionnaires/show/template-item-editor.tsx
  - resources/js/features/dossiers/follow-up/dossier-reminder-compose-dialog.tsx
  - resources/js/features/security/two-factor/use-two-factor-auth.ts
parent_task_id: TASK-5
priority: high
type: bug
ordinal: 19000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The questionnaire template item editor and the dossier reminder compose dialog both await a draft POST and then read a field off the response with no try/catch, no toast and no error state. These call LLM endpoints, which are the most failure-prone requests in the application. On failure the spinner stops and nothing at all happens - the button is silently dead.

The apply-all path in the template item editor additionally awaits N requests sequentially with no abort and no partial-failure reporting, so a mid-sequence failure leaves some suggestions applied and the user uninformed.

The two-factor auth hook is the in-repo example of doing this correctly.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A failed draft request surfaces a translated error to the user rather than silently stopping
- [x] #2 The trigger returns to an actionable state after a failure so the user can retry
- [x] #3 Applying multiple suggestions reports partial failure rather than failing silently mid-sequence, and can be cancelled
- [x] #4 Tests cover the failure path for both entry points
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Wrap generateSuggestions (template-item-editor) and generateDraft (dossier-reminder-compose-dialog) in try/catch; toast.error with translated message; ensure drafting/processing returns to actionable state (already via useHttp processing).
2. applyAllSuggestions: use AbortController, stop on abort, toast partial failure if any addSuggestion fails mid-sequence, keep remaining suggestions; Cancel button while addingAll.
3. Extend template-item-editor.test.tsx for draft failure + partial apply-all; add dossier-reminder-compose-dialog.test.tsx for draft failure.
4. Add en/nl translation keys for the error strings.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verification: vendor/bin/sail npm test -- template-item-editor.test.tsx dossier-reminder-compose-dialog.test.tsx → 10/10.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Surfaced AI draft failures with translated sonner toasts on both entry points (template item editor + dossier reminder compose). Apply-all now rejects per-item failures, reports partial failure counts, and exposes Cancel via AbortController while running. Buttons return to an actionable state after failure (useHttp processing clears). Vitest covers draft failure for both components and mid-sequence apply-all failure. en/nl keys added.
<!-- SECTION:FINAL_SUMMARY:END -->
