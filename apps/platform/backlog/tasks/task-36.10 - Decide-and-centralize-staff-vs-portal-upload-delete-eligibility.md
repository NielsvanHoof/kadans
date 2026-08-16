---
id: TASK-36.10
title: Decide and centralize staff vs portal upload-delete eligibility
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:35'
updated_date: '2026-08-14 22:46'
labels:
  - audit
dependencies: []
references:
  - app/ClientPortal/Http/Controllers/ClientPortalUploadController.php
  - app/Dossiers/Http/Controllers/UploadedDocumentController.php
  - app/Dossiers/Policies/UploadedDocumentPolicy.php
  - app/Dossiers/Transitions/DocumentRequestTransitions.php
modified_files:
  - app/Dossiers/Transitions/DocumentRequestTransitions.php
  - app/ClientPortal/Http/Controllers/ClientPortalUploadController.php
  - app/Dossiers/Policies/UploadedDocumentPolicy.php
  - tests/Feature/DocumentRequests/UploadDeleteEligibilityTest.php
  - .ai/rules/transitions-policies-http-controllers.md
parent_task_id: TASK-36
priority: medium
type: enhancement
ordinal: 11000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Upload-delete eligibility is split across two adapters that disagree. The portal requires allow_multiple && status === Submitted; the staff route and UploadedDocumentPolicy have no such guard. Staff can delete the sole file of a single-file Accepted request; clients cannot. .ai/rules/client-portal.md forbids duplicating this rule in an HTTP adapter. The likely home is a canDeleteUpload() beside existing canSubmit() on DocumentRequestTransitions — but which behaviour is correct is a product decision, so this task must decide before coding, not pick silently.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Staff and portal upload-delete eligibility is decided in one domain place, not duplicated in HTTP adapters
- [x] #2 ClientPortalUploadController no longer embeds the allow_multiple && Submitted rule locally
- [x] #3 UploadedDocumentPolicy and the staff controller use the same rule
- [x] #4 The product decision (whether staff may delete the sole file of a single-file Accepted request) is recorded with Boost record-rule
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented on the decision the user made: staff keep the wider power.

DocumentRequestTransitions::canDeleteUpload($documentRequest, $context, ?$dossier) mirrors canSubmit()'s signature and is now the single definition:
- Portal: allow_multiple && status === Submitted.
- Staff: any upload, including the sole file of a single-file accepted request.
- Both: only while the dossier is unlocked.

ClientPortalUploadController::destroy no longer inlines the rule (AC#2) and UploadedDocumentPolicy::delete now calls the same method (AC#3), so the two adapters cannot drift apart again. The policy keeps its own permission check; only eligibility moved.

Note the staff branch is not a no-op refactor of what was there: the old policy checked permission + !isLocked and had no notion of context, while the portal check lived in a controller. Expressing both through one method is what AC#1 asks for, and it makes the asymmetry visible in one place instead of implied by an absence.

AC#4: recorded with Boost record-rule into .ai/rules/transitions-policies-http-controllers.md (a new area file; the rules index gained a row for app/*/{Transitions,Policies,Http/Controllers}/**). The note states both branches, the reasoning for each, and points at the test — specifically warning the next reader not to 'fix' one side to match the other.

Tests: tests/Feature/DocumentRequests/UploadDeleteEligibilityTest.php, covers(DocumentRequestTransitions), three dataset-driven tests — the portal matrix across status x allow_multiple, staff allowed in every one of those cases including single-file accepted, and a completed dossier closed to both contexts. Datasets are the right shape here per .ai/rules/tests.md (an enum/status matrix differing only by inputs).

Behaviour-compatibility confirmed by the mapping and by the suite: nothing in tests or the staff UI depended on the previous absence of a guard, and the staff remove button was already hidden for single-file requests client-side.

Verified: full suite 1222 tests / 6892 assertions; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Upload-delete eligibility now has one definition, and the staff/portal difference is a recorded decision rather than an accident.

DocumentRequestTransitions::canDeleteUpload() mirrors canSubmit() and is called by both the portal controller (which no longer inlines allow_multiple && Submitted) and UploadedDocumentPolicy. Per the product decision: clients may only remove a file from a multi-file request still awaiting review, staff may remove any upload including the sole file of a single-file accepted request, and both are bounded by the dossier being unlocked.

Recorded with Boost record-rule in .ai/rules/transitions-policies-http-controllers.md, stating both branches and their reasoning and warning the next reader not to 'fix' one side to match the other.

Verified: 11 dataset-driven assertions lock the matrix in place, and the full suite passes at 1222 tests / 6892 assertions. PHPStan level 8, Rector and Pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
