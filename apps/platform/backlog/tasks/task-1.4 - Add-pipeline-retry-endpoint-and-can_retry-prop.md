---
id: TASK-1.4
title: Add pipeline retry endpoint and can_retry prop
status: Done
assignee:
  - '@claude'
created_date: '2026-08-05 21:40'
updated_date: '2026-08-05 22:38'
labels: []
dependencies:
  - TASK-1.1
  - TASK-1.3
references:
  - routes/tenant/reporting.php
  - app/Reporting/Http/Controllers/PipelineController.php
  - tests/Feature/DocumentProcessing/PipelineControllerTest.php
documentation:
  - docs/backend.md
  - docs/testing.md
parent_task_id: TASK-1
type: feature
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Expose manual retry over HTTP and tell the show page whether the current user may use it.

Add the route to routes/tenant/reporting.php:
POST pipelines/{pipeline}/retry -> PipelineController@retry, named pipelines.retry, with inline throttle:10,1. Inline throttle:N,1 on feature routes is the documented convention in docs/backend.md. Do not add RequirePassword: unlike the download route it sits next to, retry exposes no document content, so re-confirming the password would be friction without a security benefit.

Add PipelineController::retry() in app/Reporting/Http/Controllers/PipelineController.php, keeping it thin in the same shape as the existing destroy(): authorize('retry', $pipeline), call RetryPipelineAction::handle() with $this->currentUser(), set Toast::success, and redirect. Unlike destroy() (which redirects to the index because its subject is gone) retry should return to the show page, via to_route('workspaces.pipelines.show', $this->workspaceRouteParameters(['pipeline' => $pipeline->id])).

No Form Request: there is no input to validate, so the controller-level authorize() is the whole gate.

Also add 'can_retry' => $request->user()?->can('retry', $pipeline) ?? false to the show() props, alongside the existing can_delete and can_download.

Regenerate Wayfinder output so the frontend task has a typed route helper to import, and check whether tests/Feature/Platform/AuthorizationCoverageTest.php needs the new route registered.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 POST pipelines/{pipeline}/retry exists, is named workspaces.pipelines.retry, and is throttled with throttle:10,1
- [x] #2 The route does not use RequirePassword
- [x] #3 PipelineController::retry() authorizes via the retry policy, calls RetryPipelineAction, and stays thin with no inline validation
- [x] #4 A successful retry redirects back to the pipeline show page with a success toast
- [x] #5 A role without pipelines.retry receives 403; a pipeline from another tenant receives 404
- [x] #6 Retrying a completed or running pipeline receives 403
- [x] #7 show() exposes can_retry matching PipelinePolicy::retry() for the current user
- [x] #8 tests/Feature/Platform/AuthorizationCoverageTest.php is checked and updated if it requires the new route
- [x] #9 Feature tests added to tests/Feature/DocumentProcessing/PipelineControllerTest.php using the existing provisionWorkspace / workspaceMember / workspaceRoute helpers and Queue::fake()
- [x] #10 Wayfinder output is regenerated with 'artisan wayfinder:generate --with-form' (note: resources/js/routes and resources/js/actions are gitignored, so this output is never committed) and 'sail npx tsc --noEmit' passes
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Route in routes/tenant/reporting.php: Route::post('pipelines/{pipeline}/retry', [PipelineController::class, 'retry'])->middleware('throttle:10,1')->name('pipelines.retry'). No RequirePassword — retry streams no document content, unlike the sibling download route.
2. PipelineController::retry(Tenant, Pipeline, RetryPipelineAction): authorize('retry', $pipeline) -> $retryPipeline->handle($pipeline, $this->currentUser()) -> Toast::success(__('Pipeline retry queued.')) -> to_route('workspaces.pipelines.show', $this->workspaceRouteParameters(['pipeline' => $pipeline->id])). Unlike destroy(), which redirects to the index because its subject is gone. No Form Request: nothing to validate.
3. Add 'can_retry' => $request->user()?->can('retry', $pipeline) ?? false to show(), beside can_delete/can_download.
4. Add the 'Pipeline retry queued.' string to lang/en.json and lang/nl.json up front — LanguageCoverageTest has caught this omission in both previous tasks.
5. AuthorizationCoverageTest needs no map entry: it positively detects '$this->authorize(' in the controller method source, so calling it is sufficient. Verify by running the test rather than assuming.
6. Extend tests/Feature/DocumentProcessing/PipelineControllerTest.php (it uses test(), not it()) with Queue::fake(): owner retries a failed run -> redirect to show + job queued + attempt_number 2; reviewer -> 403; completed and running runs -> 403; cross-tenant -> 404; show exposes can_retry true for a permitted role on a failed run and false on a completed one. Reuse the file's existing pipelineDocument() helper and drive the pipeline to failed via PipelineStateAction so the fixture matches production rather than being hand-forced.
7. Regenerate Wayfinder with --with-form (bare wayfinder:generate strips .form() and breaks ~20 files; the output is gitignored so git will not warn).
8. Verify: pest on PipelineControllerTest, tests/Feature/Platform/AuthorizationCoverageTest.php, then the full backend suite; sail npx tsc --noEmit; pint on changed paths.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Corrected AC #8 at pickup: it originally required the Wayfinder output to be 'committed', which is impossible because resources/js/routes and resources/js/actions are gitignored (.gitignore:18-19). Reworded to require regeneration with --with-form plus a passing tsc, and it moved to #10 on re-add.

Two assertions were wrong on the first run and both were my error, caught by the suite rather than by inspection:
1. assertSessionHas('flash') — this app flashes toasts through Inertia::flash('toast', ...), not a session key. The codebase already has an assertInertiaFlash helper (see WorkspaceRolesPageTest); switched to assertInertiaFlash('toast.type'/'toast.message').
2. Queue::assertNothingPushed() — too broad. The fixture itself pushes an Illuminate\Broadcasting\BroadcastEvent when PipelineStateAction drives the pipeline to failed, so the assertion failed for a reason unrelated to retry. Narrowed to Queue::assertNotPushed(ProcessUploadedDocumentJob::class), which is what those tests actually mean.

Third convention guard hit this task: tests/Unit/ControllerActionsTest.php requires every non-REST controller action to be declared in CONTROLLER_NON_REST_ACTIONS. Added 'retry' alongside 'download' for PipelineController and gave the entry its own comment, since the existing 'Alternative representations / transports' grouping did not describe a lifecycle re-run.

AuthorizationCoverageTest needed no map entry — it positively detects '$this->authorize(' in the action source, so calling it is sufficient. Confirmed by running the test, not by reading the code.

Fixtures use the real state machine (CreatePipelineAction + PipelineStateAction) to reach failed/stuck rather than force-writing status columns, so they match what production leaves behind.

Validation: PipelineControllerTest 15 passed / 119 assertions; AuthorizationCoverageTest 2 passed; full backend suite 748 passed / 5078 assertions; sail npx tsc --noEmit exit 0 with retry.form present in the generated routes; pint clean. Route middleware asserted programmatically via a pest --agent probe (POST, throttle:10,1 present, no RequirePassword) rather than eyeballing route:list. A second probe drove the real HTTP endpoint on the sync queue: a document left processing with textract_job_id 'abandoned' came back completed with extracted text, pipeline failed -> completed, attempt_number 2.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Exposed manual retry over HTTP and told the show page whether the current user may use it. This completes the backend half of the feature — retry now works end to end without any UI.

Changed: POST workspaces/{tenant}/pipelines/{pipeline}/retry (named workspaces.pipelines.retry, throttle:10,1, deliberately no RequirePassword since retry streams no document content unlike the sibling download route). PipelineController::retry() stays thin — authorize, call RetryPipelineAction, Toast::success, redirect back to the show page rather than to the index as destroy() does, because the user wants to watch the reopened run. No Form Request: there is nothing to validate. show() now also exposes can_retry. Added the toast string to both locale files and declared 'retry' in ControllerActionsTest::CONTROLLER_NON_REST_ACTIONS.

Verified: 6 new tests in PipelineControllerTest (15 passed / 119 assertions in the file) covering successful retry with redirect, toast and queued job, stuck runs, 403 for a role without pipelines.retry, 403 for completed and running runs, 404 cross-tenant, and can_retry true/false on the show payload. Route middleware was asserted programmatically (POST, throttle:10,1 present, no RequirePassword) rather than read off route:list. The decisive check was a pest --agent probe against the real HTTP endpoint on a live sync queue: a document left processing with an abandoned textract_job_id came back completed with extracted text, the pipeline went failed -> completed, and attempt_number reached 2. AuthorizationCoverageTest 2 passed with no map entry needed; full backend suite 748 passed / 5078 assertions; sail npx tsc --noEmit exit 0 with retry.form generated; pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
