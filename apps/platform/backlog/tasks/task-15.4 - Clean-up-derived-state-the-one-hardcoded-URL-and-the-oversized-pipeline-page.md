---
id: TASK-15.4
title: 'Clean up derived state, the one hardcoded URL and the oversized pipeline page'
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:47'
labels: []
dependencies: []
references:
  - resources/js/pages/dossiers/review.tsx
  - resources/js/components/data/sortable/sortable-list.tsx
  - >-
    resources/js/features/document-requests/staff/builder/questionnaire-builder.tsx
  - resources/js/pages/workspaces/pipelines/show.tsx
documentation:
  - docs/frontend.md
parent_task_id: TASK-15
priority: low
type: chore
ordinal: 58000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Three small frontend conventions violations:

Three components copy props into state through an effect rather than deriving them - the dossier review page, which is the heaviest page in the application, the sortable list, and the edit role dialog. Each costs a wasted render pass on every prop change.

One hardcoded URL exists in the entire frontend, in the questionnaire builder, used by the unsaved-changes guard. Everything else uses Wayfinder correctly, so this is the sole leak and it silently breaks the guard if the route moves.

The pipeline show page is 325 lines against a documented convention of keeping pages thin - the next largest page is 229. It inlines metadata cards, badges, the delete dialog and the header actions, while the feature folder for it already exists with two components in it. Other pages over the bar: the dossier review page, the client show page and the questionnaire create page.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The three effect-copied-state instances derive their values instead
- [x] #2 The hardcoded builder path uses the generated route helper
- [x] #3 The pipeline show page delegates to feature components and is comparable in size to its peers
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Find and replace prop→state effects in dossiers/review.tsx, sortable-list.tsx, and role-form-dialog (edit role) with derived values.
2. Replace hardcoded unsaved-changes URL in questionnaire-builder.tsx with Wayfinder route helper.
3. Thin pipelines/show.tsx by extracting metadata/header/delete UI into features/pipelines components; keep page orchestration-only.
4. Run targeted Vitest / existing frontend tests; update docs/frontend.md only if size convention needs a note.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
SortableList keeps a mount-only useEffect for isClient (SSR hydration); only the items sync effect was removed.

Builder path previously omitted /workspaces/{tenant}, so the leave guard was already broken for real URLs.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
- Replaced prop→state effects with during-render sync (dossier review, sortable list) or keyed remount for initial permissions (role form dialog).
- Questionnaire builder unsaved-changes guard now uses Wayfinder `dossierBuilder.url({ tenant, dossier })` instead of a hardcoded `/dossiers/:id/builder` path (which did not match the workspace-scoped route).
- Thinned `pipelines/show.tsx` from ~317 to 91 lines by extracting `PipelineShowHeader`, `PipelineShowSummary`, and `PipelineShowDiagnostics`.

## Tests
- `vendor/bin/sail npm run lint:check` — clean
- Vitest: sortable-list + role-form-dialog — passed
<!-- SECTION:FINAL_SUMMARY:END -->
