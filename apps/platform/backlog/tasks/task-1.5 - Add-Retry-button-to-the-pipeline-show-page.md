---
id: TASK-1.5
title: Add Retry button to the pipeline show page
status: Done
assignee:
  - '@claude'
created_date: '2026-08-05 21:40'
updated_date: '2026-08-05 22:46'
labels: []
dependencies:
  - TASK-1.4
references:
  - resources/js/pages/workspaces/pipelines/show.tsx
  - resources/js/components/feedback/confirm-destroy-dialog.tsx
  - resources/js/features/clients/list/restore-client-button.tsx
  - resources/js/features/pipelines/hooks/use-reload-on-pipeline-updated.ts
documentation:
  - docs/frontend.md
parent_task_id: TASK-1
type: feature
ordinal: 6000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Put the Retry control in front of the user on workspaces/pipelines/{pipeline}, gated on the can_retry prop.

Edit resources/js/pages/workspaces/pipelines/show.tsx. Destructure the new can_retry prop with a false default, matching how can_delete and can_download are handled. Add the button to the existing header action cluster (the flex-wrap div that currently holds Download and Delete), placed before Delete so the destructive action stays last.

Reuse ConfirmDestroyDialog from @/components/feedback/confirm-destroy-dialog in its form mode with confirmVariant="default" and the Wayfinder .form() target for the new route. The closest existing template is features/clients/list/restore-client-button.tsx, which is the same shape: a non-destructive confirm on a recovery action. Do not hand-roll an AlertDialog; note the documented inversion in confirm-destroy-dialog.tsx where AlertDialogTrigger is the root.

Also extend the live-reload prop list to ['pipeline', 'can_delete', 'can_download', 'can_retry', 'flash']. Two reasons: can_retry must refresh alongside the status it depends on, and 'flash' is currently missing on the show page although the index page includes it, so a toast fired by a realtime-triggered reload is dropped today. The enabled: pipelineIsInFlight(...) gate needs no change, because after a retry the status is pending and the Echo subscription switches itself back on.

No client-side toast code is needed: use-flash-toast.ts is mounted globally in Toaster and picks up the server's Toast::success automatically.

Add all new copy as JSON sentence keys to both lang/en.json and lang/nl.json per docs/frontend.md. No new Vitest test: this is a conditional button on a page, and pages are not unit tested in this repo (features/pipelines/tests covers the status helpers, which are untouched).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The Retry button renders only when can_retry is true and is absent otherwise
- [x] #2 It sits in the header action cluster before the Delete button
- [x] #3 Pressing it opens a non-destructive confirm dialog (confirmVariant default) that submits to the retry route via a Wayfinder .form() target
- [x] #4 No hardcoded URL strings; the route helper is imported from generated Wayfinder output
- [x] #5 After a retry the page shows status pending, an incremented Attempts value, and a reset step timeline
- [x] #6 The Echo subscription re-enables itself so the run progresses without a manual refresh
- [x] #7 The live-reload only list includes can_retry and flash
- [x] #8 All new copy exists as sentence keys in both lang/en.json and lang/nl.json
- [x] #9 npm run types:check and npm test pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. resources/js/pages/workspaces/pipelines/show.tsx: destructure can_retry with a false default beside can_delete/can_download; import { retry as retryPipeline } from '@/routes/workspaces/pipelines' and RotateCcw from lucide-react.
2. Render the button inside the existing header action cluster (the flex-wrap div holding Download and Delete), placed BEFORE Delete so the destructive action stays last. Use ConfirmDestroyDialog in form mode with confirmVariant='default' and retryPipeline.form({tenant, pipeline}) — the same shape as features/clients/list/restore-client-button.tsx, which is the existing non-destructive confirm. Do not hand-roll an AlertDialog: AlertDialogTrigger is the root in this React Aria variant.
3. Extend the live-reload only list from ['pipeline','can_delete','can_download'] to add 'can_retry' and 'flash'. 'flash' is already used on the index page and is currently missing on show, so a toast fired by a realtime-triggered reload is dropped. The enabled: pipelineIsInFlight() gate needs no change since a retried run is pending and re-enables the subscription itself.
4. Copy as JSON sentence keys in lang/en.json and lang/nl.json, phrased to match the neighbouring delete dialog ('Delete pipeline run?' / 'Delete pipeline'): 'Retry', 'Retry pipeline run?', 'Retry pipeline', and the description.
5. Verify with real rendering rather than inspection: a pest --agent probe asserting the retry route URL is present in the show payload for a failed run and absent for a completed one, plus the existing Pest assertions on can_retry. Then npm test (vitest) and sail npx tsc --noEmit.
6. No new Vitest test: this is a conditional button on a page, and pages are not unit tested here (features/pipelines/tests covers the status helpers, which are untouched).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Deviated from the recorded plan in one respect, for the better. The plan said 'no new Vitest test, pages are not unit tested here'. But AC #1 is a rendering claim, and the finalization guide forbids checking acceptance criteria from code presence alone. Rather than leave it unverifiable, I extracted the button into features/pipelines/show/retry-pipeline-button.tsx — which is also what docs/frontend.md asks for ('keep pages thin: wire server props into a feature component') and mirrors the existing features/clients/list/restore-client-button.tsx. The component owns the canRetry gate and returns null when false, so both branches are directly testable.

Added resources/js/features/pipelines/tests/show/retry-pipeline-button.test.tsx (3 tests): renders nothing when canRetry is false, renders the Retry trigger when true, and the Wayfinder helper resolves to POST /workspaces/{tenant}/pipelines/{id}/retry. As with the backend regression, the first two form a pair — the positive case is what stops the empty-DOM assertion passing for the wrong reason.

can_retry is correctly FALSE immediately after a retry, because the run is then pending rather than failed or stuck. The button disappearing while the run is in flight is intended, not a bug — the same policy that authorizes the endpoint drives the prop.

Verified AC #6 from the existing suite rather than by inspection: pipelineIsInFlight('pending') is asserted true in features/pipelines/tests/status/pipeline-status.test.ts, which is exactly the condition gating the Echo subscription.

Validation: new component test 3 passed; full vitest 313 passed / 51 files; sail npx tsc --noEmit exit 0; biome check clean on 13 files; full backend suite 748 passed / 5078 assertions. A pest --agent probe asserted the show payload across a real retry (38 assertions): before, can_retry true with status failed and attempt_number 1; after, status pending, attempt_number 2, current_stage and error_message null, all three steps back to pending, can_retry false.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Put the Retry control on the pipeline show page, completing the feature.

Changed: extracted features/pipelines/show/retry-pipeline-button.tsx (owns the can_retry gate, reuses ConfirmDestroyDialog in form mode with confirmVariant='default' and the Wayfinder retry.form target, mirroring restore-client-button.tsx) and wired it into the header action cluster before Delete so the destructive action stays last. Extended the live-reload only list to ['pipeline','can_delete','can_download','can_retry','flash'] — 'flash' was missing on show though present on index, so a toast fired by a realtime-triggered reload was being dropped. Added four sentence keys to lang/en.json and lang/nl.json.

Extracting the button rather than inlining it was a deliberate change from the recorded plan: AC #1 is a rendering claim, and a component is testable where a page is not. It also matches the documented 'keep pages thin' convention.

Verified: 3 new Vitest tests prove the button is absent when can_retry is false, present when true, and targets POST /workspaces/{tenant}/pipelines/{id}/retry. A pest --agent probe drove a real retry through HTTP and asserted the show payload on both sides (38 assertions): before, can_retry true with status failed and attempt_number 1; after, status pending, attempt_number 2, current_stage and error_message cleared, all three steps reset to pending, can_retry false — the button correctly disappears while the run is back in flight. The Echo re-subscription was confirmed from the existing pipeline-status test asserting pipelineIsInFlight('pending') is true. Full vitest 313 passed / 51 files; backend 748 passed / 5078 assertions; tsc exit 0; biome clean.
<!-- SECTION:FINAL_SUMMARY:END -->
