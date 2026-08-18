---
id: TASK-32
title: Add Vitest coverage for high-impact frontend hooks
status: Done
assignee:
  - nielsvanhoof
created_date: '2026-08-10 22:35'
updated_date: '2026-08-10 22:41'
labels:
  - testing
  - frontend
  - vitest
dependencies: []
documentation:
  - docs/testing.md
  - .ai/rules/js.md
modified_files:
  - >-
    resources/js/features/document-requests/tests/staff/builder/use-field-settings-form.test.ts
  - >-
    resources/js/features/document-requests/tests/staff/builder/use-questionnaire-builder-actions.test.ts
  - resources/js/hooks/tests/use-permissions.test.ts
  - resources/js/hooks/tests/use-current-url.test.ts
  - resources/js/hooks/tests/use-appearance.test.ts
  - vitest.config.ts
priority: medium
type: chore
ordinal: 88000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Raise frontend Vitest coverage by testing high-impact uncovered modules with real logic — questionnaire builder persistence/settings, permission gating, and current-URL helpers — rather than thin Inertia page shells.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Vitest coverage added for high-impact uncovered hooks/modules with real logic (builder settings/actions, permissions, current URL at minimum)
- [x] #2 New tests live under resources/js/**/tests/ and reuse shared mocks from resources/js/test/mocks.ts
- [x] #3 npm run test:coverage passes and the lines threshold in vitest.config.ts is raised to the new measured floor without lowering it
- [x] #4 HTTP/Inertia page shells are not covered in Vitest
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add Vitest for useFieldSettingsForm (dirty/validation/type change/persist).
2. Add Vitest for useQuestionnaireBuilderActions (payload mapping, flash re-apply, failure → null).
3. Add Vitest for usePermissions and useCurrentUrl (page-prop gating / path matching).
4. Add Vitest for useAppearance (initialize + update).
5. Optionally useQuestionnaireBuilderLock releaseLock/canEdit with mocked useHttp/fetch.
6. Run npm run test:coverage via Sail; raise vitest lines threshold to new floor.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Stabilized DocumentRequest fixtures outside renderHook for useFieldSettingsForm — creating them inside the callback re-fired the reset effect every render and OOM'd the Vitest worker.

Coverage after new suites: lines 41.56% (was ~37%). Raised vitest.config.ts lines threshold 36 → 41.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added Vitest coverage for high-impact frontend hooks rather than thin Inertia pages.

**New suites**
- `useFieldSettingsForm` — dirty tracking, validation, type change, persist/canEdit
- `useQuestionnaireBuilderActions` — sync payload mapping, flash re-apply, failure → null
- `usePermissions` / `useCurrentUrl` — page-prop gating and path matching
- `useAppearance` — initializeTheme + updateAppearance

**Config**
- Raised `vitest.config.ts` lines threshold from 36 → 41 (measured 41.56%)

**Verification**
- 22 new tests pass; full suite 78 files; `npm run test:coverage` passes threshold
<!-- SECTION:FINAL_SUMMARY:END -->
