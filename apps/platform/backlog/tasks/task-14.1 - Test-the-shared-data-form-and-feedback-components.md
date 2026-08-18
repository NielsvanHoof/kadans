---
id: TASK-14.1
title: 'Test the shared data, form and feedback components'
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:06'
labels: []
dependencies: []
references:
  - resources/js/components/data
  - resources/js/components/forms
  - resources/js/components/feedback
parent_task_id: TASK-14
priority: medium
type: enhancement
ordinal: 50000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Start where the logic actually is rather than aiming for breadth: the upload file validation with its MIME and size rules, the destroy-confirmation dialog with its four-way branching, and the pagination component. Then the query toolbar and sortable list.

These are the components every list and every form in the application depends on, so a regression here is a regression everywhere.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Upload validation is tested for accepted types, rejected types, oversized files and edge cases
- [x] #2 The destroy-confirmation dialog is tested across its controlled, uncontrolled, form and callback branches
- [x] #3 Pagination, the query toolbar and the sortable list have tests
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Unit-test validateFileSize + fileTypeLabel (accepted/oversize/edge).
2. ConfirmDestroyDialog: controlled vs uncontrolled; form vs onConfirm branches.
3. IndexPagination: empty, range text, prev/next, ellipsis page window.
4. IndexQueryToolbar: search debounce flush + select ALL clears filter; mock useIndexQuery.
5. SortableList: static when disabled; optimistic reorder + rollback on false/throw.
Colocate under components/{forms,feedback,data}/tests/. Mock useTranslation; dynamic import after mocks.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
MIME accept is browser-enforced via the input accept attribute (no JS MIME reject helper yet); tests cover accept wiring + size rules + type labels.

ConfirmDestroyDialog now always binds isOpen/onOpenChange so uncontrolled form mode closes after success.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added Vitest coverage for the shared trunk components every list/form depends on.

- Upload: unit tests for validateFileSize/fileTypeLabel; FileDropzone accept wiring + oversize reject; MultiFileDropzone skip-oversize + capacity.
- ConfirmDestroyDialog: uncontrolled callback, controlled open/onOpenChange, and Inertia form submit/close. Fixed open-state wiring so form mode also closes after onSuccess.
- IndexPagination: empty, single-page range, prev/next links, ellipsis window.
- IndexQueryToolbar: search blur flush, All clears select filter, sort change (mocked useIndexQuery).
- SortableList: disabled hides handles; optimistic reorder + rollback; jsdom matchMedia polyfill in test setup.

Evidence: `vendor/bin/sail npm test -- --run` on the seven new test files — 22 passed.
<!-- SECTION:FINAL_SUMMARY:END -->
