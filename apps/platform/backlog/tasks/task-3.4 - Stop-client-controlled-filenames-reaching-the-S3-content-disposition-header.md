---
id: TASK-3.4
title: Stop client-controlled filenames reaching the S3 content-disposition header
status: Done
assignee:
  - '@claude'
created_date: '2026-08-06 09:50'
updated_date: '2026-08-06 10:50'
labels: []
dependencies: []
references:
  - app/Dossiers/Actions/DocumentRequests/ResolveDocumentTemporaryUrlAction.php
  - app/Dossiers/Actions/DocumentRequests/UploadDocumentForRequestAction.php
parent_task_id: TASK-3
priority: high
type: bug
ordinal: 12000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The temporary URL action interpolates the stored `original_filename` straight into the S3 `ResponseContentDisposition` parameter, wrapped in manual quotes. That value is the portal client raw multipart filename from `getClientOriginalName()`, never validated or length-bounded. Symfony strips directory components but not quotes, semicolons or commas.

A portal client holding a legitimate grant uploads a file whose name closes the quoted filename and opens a second one. Browsers disagree on which filename wins, so a reviewer clicking a document the UI labels as a payslip saves an attacker-named .html, .svg or .hta file to disk. There is no malware scanning, and this targets exactly the staff paid to open these documents. Certain characters also make S3 return a 400, so it doubles as a per-document denial of service.

The local disk download path is already safe because Symfony quotes the header properly. Only the S3 path is affected.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The content-disposition value is built by a library that escapes it, rather than by string concatenation
- [x] #2 A filename containing quotes, semicolons, commas or non-ASCII characters produces a single well-formed filename in the response header
- [x] #3 Filename length is bounded so S3 cannot reject the request
- [x] #4 A test covers a hostile filename end to end for both the inline and attachment dispositions
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Replace the manual string-concatenated 'ResponseContentDisposition' => $disposition.'; filename="'.$document->original_filename.'"' (both call sites: the direct temporaryUrl() path and the public-endpoint presigned-URL path) with Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition() — the same RFC 6266 builder Symfony's own BinaryFileResponse uses for the already-safe local disk download path.
2. Compute an ASCII-safe fallback filename (mirroring BinaryFileResponse::setContentDisposition()'s own algorithm: replace every non-printable-ASCII byte and literal % with _) since HeaderUtils::makeDisposition() throws if the fallback isn't pure ASCII; pass the real filename as the primary value so it still reaches modern clients via filename*=UTF-8''....
3. Bound filename length before building the header. A fixed character bound alone isn't sufficient because the filename*= form percent-encodes every non-ASCII byte (up to 12 chars per emoji), so add a hard ceiling on the built header value itself and halve the filename until it actually fits, rather than guessing a safe input-character bound.
4. Add tests: a hostile filename with an embedded quote+semicolon that would otherwise open a second filename parameter, a filename with non-ASCII/comma/semicolon characters, and a pathological very-long filename, each verified for both inline and attachment dispositions.
5. Update the two existing exact-string assertions in DocumentDownloadSignedUrlTest.php: makeDisposition() omits quotes for simple safe filenames ('filename=file.pdf' not 'filename="file.pdf"'), which is also what the already-safe local disk path already produces — confirmed via the existing local-disk test's own assertion.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
First attempt only bounded the input filename to 150 characters, which was not enough: a pest-agent probe with 400 repeated 'é' characters produced a 1070-character header (the filename*=UTF-8''... form percent-encodes every non-ASCII byte, so 2-byte characters alone expand ~6x, 4-byte emoji ~12x). Replaced the fixed bound with a hard ceiling on the actual built header value (512 chars) plus a halving loop that keeps rebuilding with a shorter filename until it fits — verified this brings both a 400x 'é' filename and a 400x emoji filename comfortably under the ceiling (299 and 274 chars respectively).

The first hostile-filename test also had a flawed assertion: it counted occurrences of the literal substring 'filename=' expecting exactly 1, but the escaped output legitimately contains that substring twice (once as the real parameter key, once inside the escaped quoted value) — the count is not the right proof. Replaced it with an exact toBe() match of the fully escaped expected string, which is the real evidence a second parameter can never be parsed out. Also corrected the payload to use the actual attack pattern from the task description (embedded '"; filename="' with a semicolon, the real HTTP parameter separator) rather than a comma, which would never have created a second parameter regardless of quoting.

Verification: vendor/bin/sail artisan test --compact tests/Feature/DocumentProcessing/DocumentDownloadSignedUrlTest.php -> 11/11 passed. Full suite: vendor/bin/sail artisan test --compact -> 768/768 passed. vendor/bin/sail bin pint --format agent on all changed files -> passed (auto-fixed mb_str_functions in the test file).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Replaced manual string concatenation of the S3 ResponseContentDisposition parameter with Symfony's HeaderUtils::makeDisposition() — the same escaping mechanism the already-safe local disk download path uses — at both call sites in ResolveDocumentTemporaryUrlAction (direct temporaryUrl and the public-endpoint presigned-URL path). Added an ASCII-safe fallback (mirroring BinaryFileResponse's own algorithm) so the real UTF-8 filename still reaches modern clients via filename*=UTF-8''.... Bounded the header itself (not just the input filename) to 512 characters via a halving loop, since percent-encoding non-ASCII bytes can otherwise expand a filename by up to 12x. Verified a hostile embedded quote+semicolon filename now produces one well-formed, exactly-escaped parameter that cannot be parsed as two, and that non-ASCII/comma/semicolon filenames and pathologically long filenames stay well-formed and bounded, for both inline and attachment. Verified with 5 new test cases plus a full suite run: 768/768 passing.
<!-- SECTION:FINAL_SUMMARY:END -->
