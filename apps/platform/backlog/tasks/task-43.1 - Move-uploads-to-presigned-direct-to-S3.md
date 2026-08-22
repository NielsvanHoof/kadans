---
id: TASK-43.1
title: Move uploads to presigned direct-to-S3
status: Done
assignee:
  - '@nielsvanhoof'
created_date: '2026-08-22 16:05'
updated_date: '2026-08-22 16:51'
labels: []
dependencies: []
parent_task_id: TASK-43
priority: high
type: feature
ordinal: 97000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Documents, portal uploads and audio recordings currently POST multipart bodies through PHP, relying on `upload_max_filesize=25M` / `post_max_size=210M` set in docker/production/php.ini. Laravel Cloud exposes no php.ini (only memory_limit is settable, via ini_set; both upload directives are PHP_INI_PERDIR), and its Cloudflare edge caps request bodies at 100 MB below Enterprise. A 10 x 20 MB document batch has two independent reasons to fail there, and TASK-42 showed that exceeding upload_max_filesize fails silently rather than returning a 413.

Move to presigned PUT: the browser uploads straight to S3, the app signs the request and then registers metadata. This removes the php.ini dependency entirely and takes 200 MB bodies off application compute.

Ship this on the current ECS stack before any infrastructure changes.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Staff document, portal document and audio recording uploads all go directly to S3 via a presigned PUT
- [x] #2 The per-dossier cumulative byte cap is still summed inside the transaction holding the dossier lock
- [x] #3 Presigned PUTs satisfy the bucket's aws:kms encryption requirement
- [x] #4 The documents bucket has a CORS rule permitting PUT from the application origin
- [x] #5 UploadLimitsTest asserts the presign constraints instead of php.ini values
- [x] #6 GuardDuty malware scanning still tags uploaded objects and ResolveMalwareScanStatusAction still reads the verdict
- [x] #7 Size and MIME constraints are enforced server-side against the stored object before the processing pipeline is dispatched, and the recorded mime type is derived server-side rather than taken from the client
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Extract the internal-vs-public S3 endpoint handling out of ResolveDocumentTemporaryUrlAction into a reusable DocumentPresigner support class, so PUT signing and the existing GET signing share one client builder.
2. Add a StagedUpload plain readonly value object (disk, path, originalFilename, mimeType, sizeBytes) in Dossiers/Support.
3. Add an upload-ticket pair of actions in Dossiers/Actions/Uploads: IssueUploadTicketAction presigns a PutObject to a staging key and returns an encrypted ticket naming the key, tenant, purpose and expiry; ClaimUploadTicketAction decrypts it, headObject-verifies the real size and content type against the purpose limits, and returns a StagedUpload. Encrypting the ticket is what stops a client choosing its own object key.
4. Sign to a staging/ prefix rather than the final dossier path, then move server-side once the upload is claimed. An unclaimed object under staging/ is unambiguous and expires on a bucket lifecycle rule; an unclaimed object in the dossier tree would not be.
5. Change UploadDocumentForRequestAction and UploadAudioRecordingAction to accept a StagedUpload and move the object into place instead of putFile-ing an UploadedFile. Their existing rollback-deletes-the-file logic covers the moved object unchanged.
6. Thread StagedUpload through UploadStaffDocumentAction and SubmitPortalUploadAction. The portal per-dossier byte cap stays exactly where it is, inside the transaction holding the dossier lock.
7. Change the three FormRequests to validate ticket strings instead of UploadedFile instances. Size and MIME enforcement moves into ClaimUploadTicketAction, which is the only place that sees the real object.
8. Add ticket-minting endpoints: a tenant-scoped one for staff and a grant-scoped one for the portal.
9. Frontend: a domain-agnostic useDirectUpload hook that mints a ticket, PUTs to S3 with XHR progress, and returns tickets; wire it into document-file-upload-form and audio-recording-upload-form, which then submit ticket strings rather than multipart bodies.
10. Infra: add a CORS configuration permitting PUT from the application origin, and a staging/ expiry rule, to the documents bucket in infra/src/shared/documents.ts.
11. Rewrite UploadLimitsTest against the presign constraints, update the affected feature tests, and add vitest coverage for the hook.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented as planned, with three findings that changed the design:

1. Content-Type cannot be signed. A presigned S3 PUT signs `host` and nothing else — confirmed against the real Floci emulator, where the URL comes back with `X-Amz-SignedHeaders=host`. The first draft claimed in several comments that the browser had to send exactly the signed type. It does not, so the Content-Type S3 records is client-controlled. Fixed by deriving the stored mime type from the sealed filename extension (UploadPurpose::mimeTypeForExtension) instead of reading it back off the object. That is stricter than the old behaviour was, since UploadedFile::getMimeType() sniffed content the client supplied. The download and preview hardening in UploadedDocumentResponse (nosniff, per-file CSP, Content-Disposition) was already written on the assumption that mime_type is untrusted.

2. Spatie Data serialises with a snake_case output mapper (config/data.php), so the hand-written camelCase TicketResponse type in the hook compiled cleanly and would have read undefined at runtime. The hook now uses the generated ambient type App.Dossiers.Data.UploadTicketData, and the vitest fixture returns snake_case so a future mismatch fails.

3. The refactor of ResolveDocumentTemporaryUrlAction onto the shared presigner initially made a missing AWS_BUCKET throw where it used to fall through to the adapter, turning every document download into a 500 in an environment that has not set it. Restored the graceful fallthrough; DocumentDownloadSignedUrlTest already covered it.

Design notes:
- Uploads land under staging/ and are moved into the dossier tree on claim, rather than being signed straight to the final path. An object left in staging/ is unambiguously abandoned, which is what makes the one-day bucket lifecycle rule safe; an orphan in the dossier tree would not be.
- The ticket is Crypt-sealed rather than a raw object key. Presigned PUTs let the browser write to the bucket; the ticket is what stops it choosing where.
- The portal per-dossier cap is now checked twice: at ticket issue, so a client is told the dossier is full before uploading 20 MB, and under the dossier lock on write, which stays the authoritative one.
- The FormRequests deliberately no longer validate files. Only ClaimUploadTicketAction can see the stored object, so size and type belong there.
- Kept useForm rather than Inertia <Form> (against .ai/rules/js.md) because the submit has to await N presigned PUTs and abandon itself if any fails. Commented in the component.

Validation:
- vendor/bin/sail artisan test --parallel: 1399 passed, 7559 assertions.
- vendor/bin/sail npm run test: 511 passed across 97 files, including 7 new useDirectUpload tests.
- cd infra && npm test: 70 passed.
- phpstan level 8, pint, rector --dry-run, tsc --noEmit, prettier: all clean.
- End-to-end against the real Floci S3 emulator via pest --agent: issue ticket -> real presigned PUT over HTTP -> claim reads back the true 11-byte size; a second run sending Content-Type: text/html for an mp3 still recorded audio/mpeg.

Not done, needs the user:
- .ai/rules/js.md ("useForm only for multipart uploads needing progress") and .ai/rules/shared.md (WAF SizeRestrictions_BODY override, "uploads go to 20 MB") are now inaccurate. The Laravel Boost MCP server is not connected in this session, so record-rule was unavailable and I did not hand-edit .ai/rules.
- resources/js/features/dossiers/show/audio-recordings-card.tsx:100 fails biome lint/a11y/useAriaPropsSupportedByRole. Confirmed pre-existing on develop by stashing this branch and re-running the check; left alone as unrelated.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Moved document, portal and audio uploads off the multipart request path and onto presigned direct-to-S3 PUTs, removing the dependency on php.ini upload limits that Laravel Cloud cannot provide and that the edge would cap at 100 MB anyway.

The browser asks a ticket endpoint to sign a PUT into a per-tenant staging/ prefix and receives an opaque Crypt-sealed ticket naming the object; the form then submits tickets instead of bytes. ClaimUploadTicketAction opens the ticket, checks purpose, tenant and expiry, reads the real size back off the stored object, derives the mime type from the sealed extension, and hands the upload actions a StagedUpload they move into the dossier tree. Unclaimed objects expire on a one-day bucket lifecycle rule.

Verified end to end against the real Floci S3 emulator (issue ticket, genuine presigned PUT, claim reads back the true size; a lying Content-Type is ignored), plus 1399 PHP tests, 511 frontend tests including 7 new hook tests, and 70 infra tests. Sixteen new tests cover the security boundary specifically: cross-tenant, cross-purpose, expired, forged, empty and oversized tickets.
<!-- SECTION:FINAL_SUMMARY:END -->
