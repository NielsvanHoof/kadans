---
id: TASK-9
title: Bring AudioProcessing under the architecture tests
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:53'
updated_date: '2026-08-06 16:14'
labels: []
dependencies: []
documentation:
  - docs/architecture.md
priority: high
type: enhancement
ordinal: 29000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The architecture doc publishes a seven-layer dependency order and states it is enforced in tests/Pest.php. AudioProcessing appears in the domain table but not in the layer list, and the word AudioProcessing does not appear in tests/Pest.php at all. So the domain may import anything and anything may import it.

Two mutual dependency cycles already exist and are invisible to CI: AudioProcessing and DocumentProcessing import each other, and AudioProcessing and Dossiers import each other. The same rule block also omits the Accounts to AudioProcessing constraint that every other domain has.

Separately the Pest Laravel arch preset is ignoring every domain folder in the application, so it only inspects app/Providers. The custom preset re-adds most of what it covers, so this is not catastrophic, but the line reads as coverage it does not provide.

Order matters here: the cycles have to be broken before the layering rule can pass, so the two structural subtasks come first and the rule lands last.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Work subtasks in order (cycles before layering):
1. 9.1 Move TranscriptionStatus to App\AudioProcessing\Enums; update imports; regenerate TS types if needed.
2. 9.2 Introduce DocumentProcessing PipelineRetryRunner contract + registry; implementations in DocumentProcessing / AudioProcessing / Dossiers; bind from domain providers; thin RetryPipelineAction.
3. 9.3 Add AudioProcessing to L4 in docs + Pest.php not-to-use rules (incl. Accounts); document any remaining deliberate siblings.
4. 9.4 Narrow/remove Laravel arch preset ignore; document authoritative preset in docs/testing.md.
Finalize each subtask then parent.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
All four subtasks Done. Remaining mutual edges are deliberate L4 siblings (Dossiers↔AudioProcessing, AudioProcessing↔DocumentProcessing).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Brought AudioProcessing under architecture tests end-to-end.

### Subtasks
- **9.1** Moved TranscriptionStatus into App\AudioProcessing\Enums (namespace-only).
- **9.2** PipelineRetryRunner contract + registry; OCR/audio/export runners owned by their domains; RetryPipelineAction no longer imports cross-domain jobs.
- **9.3** AudioProcessing at L4 in docs + Pest layering; deliberate siblings documented; arch suite green without AudioProcessing ignore.
- **9.4** Dropped Pest laravel preset (incompatible with App\{Domain}); documented qompose as authoritative in docs/testing.md.

## Tests
- Architecture AppArchitectureTest + RetryPipelineActionTest green throughout; focused audio/queue suites green on earlier subtasks.
<!-- SECTION:FINAL_SUMMARY:END -->
