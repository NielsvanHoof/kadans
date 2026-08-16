---
id: TASK-7
title: Close the i18n label-map coverage hole
status: Done
assignee:
  - Cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 15:55'
labels: []
dependencies: []
references:
  - resources/js/features/pipelines/status/pipeline-status.ts
  - tests/Unit/LanguageCoverageTest.php
  - lang/nl.json
documentation:
  - docs/frontend.md
modified_files:
  - lang/en.json
  - lang/nl.json
  - tests/Unit/LanguageCoverageTest.php
priority: medium
type: bug
ordinal: 27000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Eight pipeline status and step strings - Running, Stuck, Cancelled, Skipped, Submit, Provider processing, Process results, Transcribe - are absent from both the en and nl language files, so they render as untranslated English for Dutch users.

They escape detection because the language coverage test only matches literal `t()` calls, and these go through a label-map lookup before being translated. This is the only status module with the hole; the dossier, document request and document processing status modules are all fully covered.

Adding the keys fixes today. Extending the coverage test to scan label-map values is what closes the class of bug, and is the more important half.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 All eight strings are present in both en and nl language files
- [x] #2 The language coverage test detects strings translated via a label map, not just literal t() calls
- [x] #3 The extended test fails if any of the eight keys is removed again
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add the eight missing pipeline label strings to lang/en.json and lang/nl.json (Running, Stuck, Cancelled, Skipped, Submit, Provider processing, Process results, Transcribe) with sensible Dutch copy.
2. Extend LanguageCoverageTest with a scanner for frontend label-map string values (e.g. *_LABELS / inline `labels: Record` maps under resources/js), merged into the frontend coverage assertion.
3. Verify: full LanguageCoverageTest passes; removing one of the eight keys fails the coverage test.
4. Optionally export PIPELINE_*_LABELS for consistency with dossier/document-request modules (only if needed for scanning — prefer scanning const maps in-file without API change).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verified AC #3 by temporarily removing Transcribe from both locale files; the dedicated pipeline label-map test failed, then keys were restored.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Closed the pipeline i18n label-map hole: eight missing strings are in en/nl, and LanguageCoverageTest now scans frontend label maps so this class of miss cannot recur unnoticed.

## Changes
- Added Running, Stuck, Cancelled, Skipped, Submit, Provider processing, Process results, Transcribe to lang/en.json and lang/nl.json.
- LanguageCoverageTest: `frontendLabelMapTranslationKeysUsedInDirectory()` merges `_LABELS` / `labels` map values into frontend coverage; dedicated assertion requires the eight pipeline keys.

## Tests
LanguageCoverageTest passes. Removing `Transcribe` fails `requires pipeline label-map strings in both locale files` as expected.
<!-- SECTION:FINAL_SUMMARY:END -->
