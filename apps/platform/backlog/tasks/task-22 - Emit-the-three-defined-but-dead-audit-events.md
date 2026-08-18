---
id: TASK-22
title: Emit the three defined-but-dead audit events
status: Done
assignee:
  - '@claude'
created_date: '2026-08-08 13:34'
updated_date: '2026-08-08 14:00'
labels: []
milestone: m-0
dependencies: []
references:
  - app/Audit/Enums/AuditEvent.php
  - app/Dossiers/Http/Controllers/UploadedDocumentController.php
documentation:
  - docs/security.md
priority: high
type: bug
ordinal: 75000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Three cases of `App\Audit\Enums\AuditEvent` are defined, translated, and surfaced in the workspace activity UI, but never emitted anywhere in the application: `DocumentViewed`, `AccessDenied`, and `AudioRecordingUploaded`. Grepping for each outside the enum itself returns zero hits.

The consequence is a hole in the control that exists precisely for the threat prevention cannot cover. `UploadedDocumentController::preview` streams the raw document bytes and `::show` returns the full extracted OCR text of a payslip, bank statement, or ID document — neither writes an audit row. Only `::download` does. A staff member with the document permission can therefore read every document in the workspace and leave no trace, because the two endpoints that render content are unaudited while the one that saves a file is not.

`docs/security.md` claims typed audit events "on view, download, export, and access denial". Two of those four do not exist, so this is also a correctness problem in the documentation (tracked separately).

The wiring differs per event, hence one acceptance criterion each. `DocumentViewed` mirrors the existing `LogAuditActivityAction` call in `::download`. `AudioRecordingUploaded` belongs on the staff audio upload path, alongside the already-emitted `AudioRecordingDownloaded`. `AccessDenied` needs a design decision the implementer should make and record in the task notes — a `Gate::after` hook, an `AuthorizationException` renderer in `bootstrap/app.php`, or an event listener — the requirement being one audit row per denied authorization check without duplicating a row per request.

Constraint: `Pipeline` and `PipelineStep` deliberately do not use `LogsActivity` and write via `saveQuietly()` (see `.ai/rules/pipelines.md`). Nothing here may change that.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Requesting an uploaded document preview or extraction page writes one `document.viewed` activity row naming the subject document
- [x] #2 Uploading an audio recording as staff writes one `audio_recording.uploaded` activity row
- [x] #3 A denied policy check writes one `access.denied` activity row, and a single request cannot produce duplicate rows for the same denial
- [x] #4 No AuditEvent case remains unreferenced outside the enum, or a case deliberately kept unused is documented as such in the enum
- [x] #5 Pipeline and PipelineStep writes stay quiet — no new activity rows for step or heartbeat changes
- [x] #6 The full test suite passes under --parallel, which is what composer test and CI run
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. DocumentViewed on the extraction page, page renders only. UploadedDocumentController::show already branches on expectsJson() to serve the extraction-page island; log after authorize() on the Inertia branch only. This is load-bearing, not cosmetic: uploaded-document-show-content.tsx re-fetches show as JSON via useFetchOnPipelineUpdated on every PipelineUpdated broadcast while OCR is in flight, so logging before the branch would write one audit row per pipeline broadcast — exactly the flooding .ai/rules/pipelines.md warns about. The human act of opening the page is the auditable event; a websocket-driven refresh of an already-open page is not.

2. DocumentViewed on preview. Log after authorize() in UploadedDocumentController::preview, with a property distinguishing it from the extraction view. preview is not polled: on S3 it 302s to a presigned URL so the browser hits this origin once. Leave ::live alone — it is the polled status slice and carries no document content.

3. AudioRecordingUploaded inside the upload transaction. Inject LogAuditActivityAction into UploadAudioRecordingAction and log inside the existing DB::transaction, mirroring where UploadStaffDocumentAction logs DocumentUploaded, so a failed audit write rolls back the record and the stored file rather than leaving an unaudited recording.

4. AccessDenied via an AuthorizationException render hook. Add App\Audit\Actions\LogAccessDeniedAction and call it from an $exceptions->render(AuthorizationException) callback in bootstrap/app.php, returning null so the existing 403 to Inertia error-page path in $exceptions->respond() still runs.

   Rejected Gate::after: fifteen call sites compute can_* UI props with ->can(), several of them per row (StaffUploadedDocumentData and StaffAudioRecordingData each probe delete per document). A read-only member opening a dossier with twenty files would write twenty access.denied rows. Gate::after cannot tell a capability probe from an attempted access; an AuthorizationException can only come from authorize() or a FormRequest denial, which is what the acceptance criterion means by a denied policy check.

   Rejected $exceptions->report(): AuthorizationException is in the framework internalDontReport list, so report callbacks never fire for it.

   Scope note: abort_if/abort_unless 403s and the portal 404 concealments throw HttpException, not AuthorizationException, and stay unlogged. That is deliberate — they are not policy checks, and logging the portal 404s would write a row for every probe.

5. Regression guard. Add a unit test that reads app/Audit/Enums/AuditEvent.php and asserts every case is referenced somewhere under app/ outside the enum, so a future dead event fails CI instead of waiting for the next review.

6. Feature tests under tests/Feature/Audit: extraction page render logs once, JSON island refresh logs nothing, preview logs, live logs nothing, staff audio upload logs, audit failure rolls the upload back, and a denied policy check logs exactly one row.

7. Run the touched suites plus pint. Leave the docs/security.md wording to TASK-24, which depends on this task.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented. 885 tests pass serially, PHPStan level 8 clean, Pint applied.

DocumentViewed: UploadedDocumentController::show logs on the Inertia branch only, and ::preview logs with a surface property distinguishing the two. ::live stays unaudited. Tests cover all four cases including the negative ones, because the JSON-refresh exclusion is the whole reason the logging sits after the expectsJson() branch rather than before it.

AudioRecordingUploaded: inside UploadAudioRecordingActions existing DB::transaction, after the pipeline is created. A simulated audit failure now rolls back both the recording and the stored file, mirroring the portal upload guarantee.

AccessDenied: this needed a correction mid-implementation and the reason is worth keeping. The plan called for an $exceptions->render() callback typed on AuthorizationException. That never fires. Handler::render() calls prepareException() at line 710 before renderViaCallbacks() at line 712, and prepareException maps AuthorizationException to AccessDeniedHttpException (or to a plain HttpException when the policy set an explicit status), so by the time render callbacks run the original type is gone. The callback is now typed on Symfony HttpException and checks getPrevious() instanceof AuthorizationException, which catches both mappings and is future-proof if a policy ever denies with a status. abort()/abort_unless() throw HttpException with no previous and fall through, so middleware 403s and the portals concealed 404s stay out of the trail as intended. Both the code comment and a test record this.

Gate::after stayed rejected for the reason in the plan, and there is now a test for it: a read-only member loading the dossier index writes no access.denied rows even though several can_* probes fail during that render.

Regression guard: tests/Unit/AuditEventCoverageTest.php scans app/ plus bootstrap/app.php and fails if any AuditEvent case is never referenced. This is what would have caught the original bug.

Out of scope, found while verifying: the full suite is red under --parallel on develop, independently of this task. tests/Feature/Dossiers/DeleteUploadedDocumentActionTest.php:13 has a redundant global-scope "use RuntimeException;" which PHP warns about and --parallel escalates to an ErrorException. The file is unmodified in git and fails in isolation. composer test uses --parallel, so CI is affected. One-line deletion; awaiting the users call on whether it belongs here or in its own task.

Verification evidence, per acceptance criterion.

AC1 — tests/Feature/Audit/DocumentAccessAuditTest.php. "opening the extraction page writes a document viewed audit entry" and "previewing a document writes a document viewed audit entry" both resolve the row with sole(), so exactly one is written, and assert subject_type audio uploaded_document plus subject_id. The two negative cases matter as much: "the extraction island json refresh writes no audit entry" and "the live status island writes no audit entry" pin the boundary that keeps websocket-driven refreshes out of the trail.

AC2 — tests/Feature/AudioRecordings/UploadAudioRecordingLifecycleTest.php, "uploading an audio recording writes a tenant scoped audit entry", also via sole(), asserting subject, tenant_id and causer. "an audio upload rolls back database and storage when its audit record fails" proves the placement inside the transaction.

AC3 — "a denied policy check writes exactly one access denied audit entry" uses sole() on the access.denied rows for a read-only member hitting the download route, and asserts method, status and route. Duplicate protection is structural rather than defensive: a request raises at most one AuthorizationException before it terminates, and sole() would fail on a second row.

AC4 — tests/Unit/AuditEventCoverageTest.php scans every php file under app/ plus bootstrap/app.php and fails listing any AuditEvent case with no reference. Passing means zero dead cases remain.

AC5 — tests/Feature/Pipelines/PipelineWritesStayQuietTest.php, added during finalization rather than checked from greps. It clears the activity table, then drives start, heartbeatStep, complete, heartbeat, start, skip and fail through PipelineState and asserts zero rows. The assertion has teeth because the audit tests above prove rows are written in this same environment, so a zero count reflects quiet writes rather than disabled logging.

AC6 — 887 tests pass under artisan test --parallel in 11.9s. Three test files carried a redundant global-scope "use RuntimeException;" that PHP warns about and --parallel escalates to an ErrorException, so composer test and CI were red before this task: DeleteUploadedDocumentActionTest, PurgeLegalRetentionTest, InvitationAndRoleTransactionTest. The serial run reported exactly three warnings, which matched. Imports removed; the class is still resolved from the global namespace, so no behaviour changed. Only non-namespaced Pest files are affected — the same import inside app/ is meaningful and was left alone.

Also clean: PHPStan level 8 with zero errors, Pint with no changes outstanding.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Wired up the three AuditEvent cases that were defined, translated, and surfaced in the activity UI but never emitted, closing a hole where preview and the extraction page handed over document content with no trail while only download was recorded.

DocumentViewed is logged on UploadedDocumentController::show for page renders and on ::preview, with a surface property separating them; the expectsJson() island refresh and the polled ::live slice stay unaudited so PipelineUpdated broadcasts cannot flood the log. AudioRecordingUploaded is logged inside UploadAudioRecordingActions transaction, so a failed audit write rolls back the recording and its stored file. AccessDenied is logged from a new App\Audit\Actions\LogAccessDeniedAction, driven by an exception render hook in bootstrap/app.php.

That hook needed a correction the plan had wrong: Handler::render() runs prepareException() before renderViaCallbacks(), so a callback typed on AuthorizationException never fires — the exception is already an AccessDeniedHttpException. It is typed on Symfony HttpException and matches on getPrevious() instanceof AuthorizationException, which covers both of prepareExceptions mappings; abort() 403s have no previous and stay out, keeping middleware denials and the portals concealed 404s unlogged. Gate::after was rejected because can_* props probe policies several times per rendered row and would log a denial per probe.

Two regression guards were added rather than left implicit: a unit test failing on any unreferenced AuditEvent case, and a feature test asserting PipelineState transitions write no activity rows.

Verified with 887 tests passing under --parallel, PHPStan level 8 clean, and Pint clean. Fixing three redundant global-scope RuntimeException imports also restored the parallel suite, which was red on develop independently of this work.
<!-- SECTION:FINAL_SUMMARY:END -->
