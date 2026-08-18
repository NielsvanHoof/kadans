---
id: TASK-5.3
title: Translate and deduplicate the two-factor setup errors
status: Done
assignee:
  - cursor
created_date: '2026-08-06 09:52'
updated_date: '2026-08-06 12:44'
labels: []
dependencies: []
references:
  - resources/js/features/security/two-factor/use-two-factor-auth.ts
  - resources/js/features/security/two-factor/two-factor-setup-step.tsx
parent_task_id: TASK-5
priority: medium
type: bug
ordinal: 20000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The two-factor auth hook pushes three hardcoded English strings into an error array. None of them exist in the en or nl language files, and the file is one of the few components with no translation hook.

The errors are also appended rather than replaced, so retrying setup three times shows the same sentence three times in the alert.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The three failure messages are translated and present in both en and nl language files
- [x] #2 Retrying setup replaces the previous error rather than appending, so messages cannot stack
- [x] #3 The language coverage test passes with the new keys
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Wire useTranslation into use-two-factor-auth (or pass t from callers) for the three failure messages.
2. Replace append-on-error with replace (setErrors([msg]) or clear then set) so retries do not stack.
3. Add the three keys to en.json and nl.json; run LanguageCoverageTest.
4. Update use-two-factor-auth.test.ts expectations for translated keys and non-stacking.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verification: vendor/bin/sail npm test -- use-two-factor-auth.test.ts → 9/9; vendor/bin/sail artisan test --compact tests/Unit/LanguageCoverageTest.php → 4/4.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Translated the three 2FA setup failure messages via useTranslation and added en/nl keys. Failures now replace the errors array instead of appending, so retries cannot stack duplicate alerts. Vitest covers translation keys, non-stacking retries, and LanguageCoverageTest passes.
<!-- SECTION:FINAL_SUMMARY:END -->
