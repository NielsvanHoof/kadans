---
paths:
  - 'app/**/{Transitions,Policies,Http/Controllers}/**'
---

# Transitions Policies Http Controllers

## Staff and portal upload-delete eligibility differ on purpose
Eligibility to delete an uploaded file lives in one place: `DocumentRequestTransitions::canDeleteUpload($documentRequest, $context, ?$dossier)`. Never re-inline it in a controller or policy — `ClientPortalUploadController` used to, and the staff route had no guard at all, so the two adapters silently disagreed.

The two contexts are deliberately NOT symmetric (decided in TASK-36.10):

- **Portal**: only `allow_multiple && status === Submitted`. Once an item is accepted or rejected it is a reviewed record; a client pulling the file out would leave the review describing something that is no longer there.
- **Staff**: any upload, including the sole file of a single-file accepted request. Staff are named in the audit trail, and fixing a client's mistake (wrong payslip on an accepted item) is ordinary work that should not require rejecting and re-requesting a document the client already sent.

Both are bounded by `! $dossier->status->isLocked()` — a completed dossier is closed to everyone.

The asymmetry is locked in by `tests/Feature/DocumentRequests/UploadDeleteEligibilityTest.php`. If you find yourself "fixing" one side to match the other, read that test first: the difference is the decision.
