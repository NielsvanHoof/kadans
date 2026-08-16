---
id: TASK-39
title: Authorize builder canvas sync per operation
status: Done
assignee: []
created_date: '2026-08-15 12:44'
updated_date: '2026-08-15 12:49'
labels: []
dependencies: []
priority: high
type: bug
ordinal: 92000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The dossier builder canvas endpoint `PUT workspaces/{tenant}/dossiers/{dossier}/document-requests/sync` performs bulk create, update, and irreversible hard-delete of document requests, but authorizes only a create-grade permission set.

`SyncDocumentRequestsRequest::authorize()` delegates to `AuthorizesDossierBuilderMutation::userHoldsBuilderLockForMutation()`, which checks `update` on the dossier, class-level `create` on DocumentRequest, and the builder lock. It never checks `document_requests.delete` or per-row `document_requests.update`, which the single-item `destroy` and `update` endpoints do enforce. `DocumentRequestController::sync` calls no `authorize()` of its own, the route carries no `can:` middleware, and the action performs no authorization.

Deletion is irreversible: DocumentRequest has no SoftDeletes, `uploaded_documents.document_request_id` cascades on delete, and `SyncDossierDocumentRequestsAction::deleteStoredFile()` removes the underlying object-storage blobs.

The permissions are genuinely separable. DocumentRequestPermission exposes View/Create/Update/Delete as distinct cases with distinct UI labels, and custom-role creation validates each against `assignablePermissionValues()`, so a firm can define a role holding Create without Update or Delete. Default roles (Administrator, Adviser) bundle all three, so default deployments are unaffected.

Found by a security review of the branch and reproduced end-to-end: a member on a custom role holding only dossiers.view, dossiers.update, document_requests.view and document_requests.create received 403 on `DELETE .../document-requests/{id}` with the row surviving, then 200 on `PUT .../document-requests/sync` with `{"items": []}`, after which the row was gone.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 sync authorizes can('delete', $row) for every existing document request whose id is absent from the payload
- [x] #2 sync authorizes can('update', $row) for every existing document request retained in the payload
- [x] #3 a role with create but not delete gets 403 from sync with an empty items payload, and the rows survive
- [x] #4 a role with create but not update gets 403 from sync when it edits a retained row
- [x] #5 Administrator and Adviser default roles keep working: the existing builder save flow is unchanged
- [x] #6 the per-row check adds no N+1: dossier and tenant relations are resolved once for the whole set
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add a per-operation authorization pass to SyncDocumentRequestsRequest::authorize(), after the existing coarse builder-lock gate.
2. Derive kept ids from the raw items payload defensively (authorize runs before validation), treating a missing/malformed payload as 'everything is being deleted' so it fails safe.
3. Load the dossier's existing document requests once with the tenant relation eager loaded, and seed the dossier relation on each row so DocumentRequestPolicy::canActOnOpenDossier does not lazy-load per row.
4. Require delete on omitted rows and update on retained rows.
5. Cover with feature tests in tests/Feature/Dossiers/, including the default-role happy path.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Fix lands entirely in SyncDocumentRequestsRequest::authorize(), which now ANDs a new userMayReplaceExistingDocumentRequests() pass onto the existing coarse builder-lock gate. Per row: retained ids require can('update', $row), omitted rows require can('delete', $row) — the same abilities DocumentRequestController::destroy and UpdateDocumentRequestRequest enforce one row at a time.

Decisions:
- Kept ids are read from unvalidated input because authorize() runs before rules(). A missing or malformed items payload yields an empty retained set, so every row falls under delete and the gate fails closed rather than open. Cost is that a malformed payload from a user without delete gets 403 instead of 422, which is the safe direction.
- No authorization added inside SyncDossierDocumentRequestsAction. The TOCTOU window between authorize() and the transaction is already closed by the builder lock: every builder mutation path requires holding it, and the action re-locks the dossier row. Actions in this codebase do not authorize (see .ai/rules/controllers.md).
- Avoided N+1 by eager loading tenant and seeding the already-resolved dossier onto each row via setRelation, since DocumentRequestPolicy::canActOnOpenDossier reads both.

Adjacent observation, deliberately NOT changed: ReorderDocumentRequestsRequest uses the same coarse trait and mutates sort_order on existing rows without an update check. Same class of gap, far lower impact (ordering only, no data loss). Raised with the user rather than folded into this fix.

Validation:
- tests/Feature/Dossiers/BuilderSyncAuthorizationTest.php, 5 tests. Confirmed as a real regression guard: with the authorize() change stashed, the two new authorization tests fail with 200 instead of 403.
- Full suite: 1265 passed, 6966 assertions.
- Pint clean (reordered class elements), PHPStan 0 errors on the changed file.
- N+1 probe with 10 rows: 4 tenant selects, 2 dossier selects, constant rather than per-row.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Authorized the builder canvas sync endpoint per operation instead of once for the whole canvas. SyncDocumentRequestsRequest::authorize() now requires document_requests.delete for every existing row the payload omits and document_requests.update for every row it retains, closing a path where a custom role holding only create could hard-delete document requests and their uploaded files — an action the same role was 403'd from performing one row at a time. Verified with 5 new feature tests in tests/Feature/Dossiers/BuilderSyncAuthorizationTest.php (proven to fail without the fix), a green full suite of 1265 tests, Pint, PHPStan, and a query-count probe confirming no N+1.
<!-- SECTION:FINAL_SUMMARY:END -->
