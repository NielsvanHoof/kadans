---
id: TASK-15.5
title: Fix timer leaks and improve perceived load on the heaviest page
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:51'
labels: []
dependencies: []
references:
  - resources/js/features/security/two-factor
  - app/Shared/Queries/PaginatedIndexQuery.php
  - resources/js/pages/dossiers/show.tsx
documentation:
  - docs/frontend.md
parent_task_id: TASK-15
priority: low
type: chore
ordinal: 59000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two timers in the two-factor components have no cleanup, so both leak if the modal unmounts mid-flight.

There is no prefetching anywhere in the application, and only one deferred prop - the notification inbox. The dossier show page blocks first paint on its activity and audio recording payloads, both of which are below the fold and are the obvious candidates to defer. Adding link prefetching to the dossier and client list title links is the cheapest perceived-performance win available.

Also: the per-page size default is shipped to every index page in the toolbar metadata and never read by any consumer. Either drop it or add the page-size control it implies.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Both timers are cleared on unmount
- [x] #2 The dossier show page defers its below-the-fold payloads with an animated skeleton
- [x] #3 List title links prefetch
- [x] #4 The unused per-page metadata is either consumed or removed
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Find 2FA timers without cleanup; clear on unmount.
2. Defer dossier show below-fold props (activity + audio) with Inertia::defer + skeleton UI.
3. Add prefetch on dossier/client list title links.
4. Remove unused per_page from PaginatedIndexQuery meta (or wire a control — prefer remove if unused).
5. Tests: 2FA timer cleanup if covered; feature test for deferred props; Vitest/lint as needed.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Deferred payloads are top-level `exports` / `audio_recordings` (not nested under dossier) so Deferred data keys stay simple; dossier DTO keeps empty arrays for those keys for type compatibility.

per_page remains on Laravel paginator payloads; only the unused toolbar metadata default was removed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
- Cleared 2FA focus/scroll timers on unmount in verification step and recovery codes.
- Deferred dossier show `exports` and `audio_recordings` behind Inertia deferred props with pulsing Card skeletons; first paint no longer waits on those pipeline-heavy graphs.
- Prefetch on dossier list title and client list name Links.
- Removed unused toolbar `defaults.per_page` from PaginatedIndexQuery consumers and IndexQueryConfig (pagination still uses `perPage()` internally).

## Tests
- `DossierShowPageTest` + IndexQuery tests — 24 passed
- `npm run lint:check` — clean
- Vitest index-query-toolbar — passed
<!-- SECTION:FINAL_SUMMARY:END -->
