---
id: TASK-36.12
title: Replace DocumentRequest configuration subscripting with accessors
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:35'
updated_date: '2026-08-14 22:49'
labels:
  - audit
dependencies: []
references:
  - app/Dossiers/Models/DocumentRequest.php
modified_files:
  - app/Dossiers/Models/DocumentRequest.php
  - app/Dossiers/Transitions/DocumentRequestTransitions.php
  - app/Dossiers/Http/Requests/DocumentRequests/StoreUploadedDocumentRequest.php
  - app/ClientPortal/Http/Requests/StorePortalUploadedDocumentRequest.php
  - app/Dossiers/Actions/DocumentRequests/UploadDocumentForRequestAction.php
  - >-
    app/Dossiers/Actions/DocumentRequests/ResolveApplicableDocumentRequestsAction.php
  - tests/Unit/Models/DocumentRequestConfigurationTest.php
parent_task_id: TASK-36
priority: low
type: enhancement
ordinal: 12000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
($x->configuration['allow_multiple'] ?? false) === true is character-identical in five files, with about 17 raw configuration subscript sites overall. Two accessors on DocumentRequest collapse most of the allow_multiple reads. Primitive obsession, not a new framework.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 allow_multiple and related configuration flags are read through DocumentRequest accessors, not raw subscript plus === true
- [x] #2 The character-identical (configuration['allow_multiple'] ?? false) === true sites are collapsed
- [x] #3 No new shared abstraction across domains; accessors stay on the model
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented.

Two named methods on DocumentRequest: allowsMultipleUploads() and isRequired(). Six reader sites collapsed — the five character-identical (configuration['allow_multiple'] ?? false) === true expressions in StoreUploadedDocumentRequest, StorePortalUploadedDocumentRequest, UploadDocumentForRequestAction and DocumentRequestTransitions (x2, the second being the canDeleteUpload added in TASK-36.10), plus the is_required read in ResolveApplicableDocumentRequestsAction.

Plain named methods, not Casts\Attribute accessors: the repo has ZERO Attribute precedent (grep for Attribute::make / ': Attribute' in app/ returns nothing), and its established idiom for 'one definition of a derived rule' is a plain method — DocumentRequest::isOrderedBefore(), whose docblock makes exactly that argument. AC#1 asks for accessors; a named reader method is the accessor this codebase actually uses.

Left alone on purpose: NormalizeQuestionnaireItemConfigurationAction, which owns the WRITE side and reads a raw payload array before any model exists — it cannot use a model method, and it is the one place the defaults should be spelled out.

AC#3 holds: both methods sit on the model; no shared abstraction was introduced.

Tests: tests/Unit/Models/DocumentRequestConfigurationTest.php (Unit/Models runs without the DB per tests/Pest.php), covers(DocumentRequest). The datasets target the defaulting rather than the happy path, because that is what five hand-written copies would have drifted on: absent key, null configuration, and non-boolean JSON values. It also pins the detail most likely to be got wrong — the two defaults are OPPOSITES (allow_multiple defaults false, is_required defaults true), so they cannot share one helper.

Not in scope, noted for the record: resources/js has four sites reading documentRequest.configuration?.allow_multiple ?? false. A PHP model method cannot help those; they would need a shared TS selector or a server-side boolean on StaffDocumentRequestData. AC#3 confines this task to model accessors, so the TS side is untouched.

Verified: full suite 1222 tests / 6892 assertions before the new file, 12 tests in Unit/Models after; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
allow_multiple and is_required are read through named methods on DocumentRequest instead of six hand-written subscript expressions.

allowsMultipleUploads() and isRequired() replace the five character-identical (configuration['allow_multiple'] ?? false) === true sites plus the is_required read. They are plain named methods rather than Casts\Attribute accessors because the repo has no Attribute precedent at all and already expresses 'one definition of a derived rule' this way — see DocumentRequest::isOrderedBefore().

NormalizeQuestionnaireItemConfigurationAction keeps its raw reads: it owns the write side and normalises a payload before any model exists.

Verified: full suite 1222 tests / 6892 assertions passes, plus a new Unit/Models file whose datasets target the defaulting rather than the happy path — absent key, null configuration, non-boolean values — and pin the detail most likely to drift, that the two defaults are opposites. PHPStan level 8, Rector and Pint clean.

Out of scope and recorded: four resources/js sites read the same key and would need a TS selector or a server-side boolean prop; AC#3 confines this task to model accessors.
<!-- SECTION:FINAL_SUMMARY:END -->
