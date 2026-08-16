---
id: TASK-36.11
title: Fail closed when a document disk or bucket config is missing
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:35'
updated_date: '2026-08-14 22:19'
labels:
  - audit
dependencies: []
references:
  - app/Dossiers/Actions/DocumentRequests/ResolveDocumentTemporaryUrlAction.php
  - app/Dossiers/Http/Controllers/UploadedDocumentController.php
  - config/filesystems.php
  - .ai/rules/app.md
modified_files:
  - app/Dossiers/Actions/DocumentRequests/ResolveDocumentTemporaryUrlAction.php
  - app/Dossiers/Http/Controllers/UploadedDocumentController.php
  - tests/Feature/DocumentProcessing/DocumentDownloadSignedUrlTest.php
parent_task_id: TASK-36
priority: medium
type: bug
ordinal: 10000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
ResolveDocumentTemporaryUrlAction interpolates a persisted disk name into Config::string('filesystems.disks.'.$document->disk.'.driver'). A row naming a removed disk throws InvalidArgumentException instead of returning null — 500 on the document page. The same file later reads filesystems.disks.s3.bucket, which is env('AWS_BUCKET') with no default (the .ai/rules/app.md trap). Nearby lines already get this right with Config::get().
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A document whose persisted disk name is no longer in filesystems.disks does not 500 the document page
- [x] #2 Missing AWS_BUCKET does not throw from this action via Config::string on a bare env value
- [x] #3 The action returns null (or equivalent safe miss) when the disk cannot be resolved, consistent with the Config::get() path already in the same file
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented.

Two Config::string() calls on values that can legitimately be absent, plus a genuine fail-closed path the ACs imply but the task text does not spell out.

1. supportsTemporaryUrl() interpolated a persisted disk name into Config::string('filesystems.disks.'.$disk.'.driver'). 'disk' is a plain string column written at upload time, so it is DATA and can outlive the disk it names — a row naming a removed disk threw InvalidArgumentException and 500'd the download route. Extracted diskDriver(): ?string using Config::get(), with supportsTemporaryUrl() now expressed in terms of it.

2. Config::string('filesystems.disks.s3.bucket') on a bare env('AWS_BUCKET') with no default — the exact .ai/rules/app.md trap: the key EXISTS as null when unset, so a default passed to Config::string() is ignored. Now Config::get() plus an explicit is_string check; a miss returns null and falls through to the adapter's own temporaryUrl(), which is the path taken whenever the endpoints match anyway.

3. Returning false from supportsTemporaryUrl() was NOT sufficient on its own: serveDocument()'s streaming fallback calls Storage::disk($document->disk), which throws its own InvalidArgumentException for an unconfigured disk — the 500 would simply have moved. The controller now branches on diskDriver(): null reports a RuntimeException naming the document id and disk (operators need to know a row outlived its disk) and aborts 404, since the bytes are genuinely unreachable and the caller cannot act on a 500 either way.

Tests added to tests/Feature/DocumentProcessing/DocumentDownloadSignedUrlTest.php:
- diskDriver() returns null for an unconfigured disk and the real driver for a configured one.
- Downloading a document whose disk was removed returns 404 and reports, asserted via Exceptions::assertReported.
- A missing bucket falls through to the adapter rather than throwing.

All three confirmed non-vacuous by restoring Config::string(): the first two fail with 'Configuration value for key [filesystems.disks.retired-archive.driver] must be a string, NULL given' and 'Expected response status code [404] but received 500' — the exact symptom AC#1 names.

The bucket test was caught being vacuous on the first pass: it mocked FilesystemAdapter, so generatePublicPresignedUrl() returned at the '! $disk instanceof AwsS3V3Adapter' check and never reached the bucket line. Rewritten to mock AwsS3V3Adapter with a stubbed getClient(), after which restoring Config::string() fails it with 'Configuration value for key [filesystems.disks.s3.bucket] must be a string, NULL given'.

Verified: 205 tests pass across DocumentProcessing, Dossiers and Architecture; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Document delivery now fails closed instead of 500ing on missing configuration.

diskDriver() replaces a Config::string() call that interpolated a persisted disk name — data that can outlive the disk it names — and the s3 bucket is read with Config::get() plus an explicit check, because AWS_BUCKET has no default so the key exists as null and a Config::string() default is ignored. Returning false alone would only have moved the 500 to Storage::disk() in the streaming fallback, so the controller now reports and 404s when the disk cannot be resolved: the bytes are unreachable, and operators still get the signal.

Verified with three tests, each confirmed to fail when Config::string() is restored — including the literal 'received 500' that AC#1 names. One of them was caught being vacuous first (it mocked the wrong adapter class and never reached the bucket line) and rewritten. 205 tests pass across DocumentProcessing, Dossiers and Architecture; PHPStan level 8, Rector and Pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
