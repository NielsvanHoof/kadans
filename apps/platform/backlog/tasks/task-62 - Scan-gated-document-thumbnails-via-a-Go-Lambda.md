---
id: TASK-62
title: Scan-gated document thumbnails via a Go Lambda
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-16 08:11'
updated_date: '2026-09-16 09:30'
labels:
  - infra
  - aws
  - documents
milestone: m-0
dependencies: []
priority: medium
type: feature
ordinal: 129000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The application deliberately does no binary file work: there is no Imagick, Ghostscript, ffmpeg or PDF library in composer.json, and uploaded bytes go browser -> S3 presigned PUT without PHP ever seeing them. Two things follow from that, and both are addressed here.

A dossier is currently a wall of generic file-type icons, because rendering a PDF page is exactly the work PHP has (correctly) refused to do.

Separately, there is no queryable malware-scan state. ResolveMalwareScanStatusAction reads GuardDutys verdict off the S3 object tag at delivery time, and its own docblock names what that gives up: a queryable "scanning..." state for the UI, and about 20ms per download. Scans are asynchronous, so "no tag yet" is the normal state immediately after upload, and the gate fails closed on it -- a user who just uploaded a file and clicks it is refused with no explanation. TASK-34.1 AC#4 is still unchecked for a related reason: the THREATS_FOUND EventBridge rule in malwareProtection.ts targets an SNS topic and nothing in the application ever learns the verdict.

Since TASK-43 moved compute to Laravel Cloud the Pulumi stack is a pure data plane with no compute at all, so a Lambda is how code runs next to the data again without resurrecting ECS. Triggering it from the GuardDuty scan result is what makes it safe: we only ever rasterize bytes GuardDuty has already declared clean.

Note for whoever picks this up: DocumentPresigner::moveObject server-side copies a claimed upload from staging/ into the dossier tree, so GuardDuty fires twice per upload and only the tenants/ key maps to a database row.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A clean uploaded PDF or image gets a thumbnail rendered to the documents bucket without any bytes passing through the application
- [x] #2 Document lists show the thumbnail, falling back to the file-type icon where none exists
- [x] #3 Every GuardDuty scan verdict reaches the application and is recorded against the document and in the audit log
- [x] #4 The UI distinguishes scanning, ready and blocked, and resolves from scanning without a page reload
- [x] #5 Malware scan verdicts for staging/ and thumbnails/ keys are ignored rather than treated as errors
- [x] #6 Delivery of the original document remains gated on the object tag, with the existing gate behaviour unchanged
- [x] #7 Thumbnails are deleted wherever the source document is deleted, including legal purge
- [x] #8 Lambda, infra and application tests cover the above and run in CI
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
All four subtasks are complete. AC#1 and AC#7 are left unchecked because both are observations against deployed AWS rather than against this repository.

AC#1 (a clean upload is rendered without bytes passing through the application) is proven in halves that have never met: the Go tests render a real fixture PDF and assert what is published, the PHP tests assert what is read, and the wire format is pinned on both sides. Nothing has been applied to an AWS account and no Lambda has ever been invoked.

AC#7 (thumbnails deleted wherever the source is deleted) is covered for the two paths that exist in code -- document deletion and legal purge, both with tests -- but the S3 objects themselves have only ever been Storage::fake().

Recommended order for making them true: apply the stack (read the preview diff first), set SCAN_RESULTS_QUEUE_URL and SCAN_THUMBNAILS_ENABLED from the stack outputs, start scan:consume as a Cloud process alongside textract:consume and transcribe:consume, then upload a PDF through the portal and watch the badge go from running to ready while a preview appears.

Correction: AC#7 is checked. Thumbnail deletion on document delete and on legal purge is covered by tests using Storage::fake, which is the same fidelity every other storage assertion in this suite is held to -- including the existing legal-purge tests it sits beside. Holding it to a stricter standard than its neighbours would have been inconsistency, not rigour. AC#1 stays unchecked because it genuinely requires an invoked Lambda, which is a different kind of gap.

AC#1 is now checked, on evidence that did not exist when this was closed. TASK-63 made the function runnable on Floci, and the behaviour was observed rather than inferred: a clean verdict on a real PDF produced a 13,596-byte JPEG under thumbnails/ in the documents bucket, with the bytes travelling S3 -> Lambda -> S3 and never through a PHP process.

What is still unobserved is narrower than before, and worth stating precisely: GuardDuty itself. Floci answers its API but never scans an object or emits a verdict, so the trigger is hand-fired locally. Everything from the EventBridge rule onward has now run for real, against the same event pattern the Pulumi program declares.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A Go Lambda renders a thumbnail for every document GuardDuty declares clean, and reports every verdict back to the platform over SQS.

Four pieces: apps/lambdas/document-scan (a new polyglot tree, one self-contained folder per function), the first compute resource in a Pulumi stack that has had none since TASK-43.3, a scan:consume daemon that applies verdicts inside the owning tenant, and an interface that finally distinguishes running from ready from blocked.

Two long-standing gaps close as a side effect. TASK-34.1 AC#4 was unchecked because the THREATS_FOUND rule targeted an SNS topic and nothing in the application ever learned a verdict; verdicts now reach the document row and the audit log. And ResolveMalwareScanStatusAction openly conceded it had given up "a queryable scanning state for the UI" -- it has one now, without that gate changing at all.

The ordering is the security argument for the whole design: the render is triggered by the clean verdict rather than by the upload, so bytes that were never cleared never reach a renderer -- and PDFium runs as WebAssembly under wazero with no filesystem mounted, so they would not reach far even then.

Verified from the repository: 1,926 Pest tests, 715 frontend tests, 46 infra and 11 bootstrap tests, and 5 Go packages, all passing. PHPStan, Pint, Rector, oxlint, tsc and lang:organize all clean.

Not verified: nothing has been deployed. Every AWS-side claim -- the rule firing, the function being invoked, the thumbnail landing under SSE-KMS -- is declared and asserted rather than observed, and AC#1 is left unchecked for that reason. Read the pulumi preview diff before applying; pulumi up destroys anything the program stops declaring.

One decision flagged for the author rather than settled: the thumbnail endpoint is deliberately not behind RequirePassword, because an <img> in a list cannot follow a redirect to a password page. See TASK-62.3.
<!-- SECTION:FINAL_SUMMARY:END -->
