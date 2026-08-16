---
id: TASK-13.3
title: Unify the status badges and bring pipelines onto the shared visual language
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 16:54'
labels: []
dependencies: []
references:
  - resources/js/features/dossiers/status/dossier-status-badge.tsx
  - >-
    resources/js/features/document-requests/status/document-request-status-badge.tsx
  - resources/js/features/pipelines/status/pipeline-status.ts
parent_task_id: TASK-13
priority: medium
type: enhancement
ordinal: 48000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The dossier status badge and the document request status badge are structurally identical - same mark map and class map shape, same outlined badge with the same gap and shrink classes, same audit mark. Only the two maps and the label function differ.

Pipelines opted out entirely: the pipeline status module returns shadcn variant names and renders no audit mark. So the pipelines list shows solid-filled badges with no shape cue while every other list shows outlined badges with a mark - and the doc comment on the document request badge explicitly claims shape carries the meaning so the column still reads when colours are stripped out. On pipelines, it does not, which matters for colour-blind users and printed output.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 One status badge primitive is parameterised by mark map, class map and label function
- [x] #2 Dossier, document request and pipeline statuses all use it
- [x] #3 Pipeline statuses carry a non-colour shape cue like every other status
- [x] #4 The claim that shape carries the meaning holds for every status column, verified with colour removed
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add components/primitives/status-badge.tsx parameterized by mark, className, and label.
2. Thin dossier + document-request badges to maps + StatusBadge.
3. Add PipelineStatusBadge with mark/class maps; use it in pipelines list; drop pipelineStatusBadgeVariant from the list path.
4. Vitest: StatusBadge renders mark+label; pipeline badge has AuditMark; greyscale/forced-colors or aria/data-mark assert shape cue without relying on colour alone.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Added `StatusBadge` primitive (mark + status classes + label). Dossier and document-request badges are thin maps over it. New `PipelineStatusBadge` brings pipelines onto the same outlined + audit-mark language; list and show pages use it. Removed `pipelineStatusBadgeVariant`. AuditMark exposes `data-audit-mark` so shape cues are assertable without colour.

## Tests
status-badge, pipeline-status-badge, dossier-status-badge, pipeline-status — all green.
<!-- SECTION:FINAL_SUMMARY:END -->
