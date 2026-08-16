---
id: TASK-5
title: Fix user-facing frontend defects
status: Done
assignee:
  - cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 12:44'
labels: []
dependencies: []
documentation:
  - docs/frontend.md
priority: high
type: bug
ordinal: 17000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Three defects that a user hits directly: an inverted navigation ternary, two AI endpoints with no error path, and untranslated 2FA errors that stack up. Each subtask is a small, independent change.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Work subtasks in order: 5.1 (error logo ternary), 5.2 (AI draft failure UX), 5.3 (2FA error i18n/dedupe). Each independently shippable.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Closed all three user-facing frontend defects: inverted error-page logo auth redirect (5.1), silent AI draft failures (5.2), and untranslated/stacking 2FA setup errors (5.3).
<!-- SECTION:FINAL_SUMMARY:END -->
