---
id: TASK-62.4
title: Show document previews and scan state in the UI
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-16 08:13'
updated_date: '2026-09-16 09:12'
labels:
  - frontend
  - documents
milestone: m-0
dependencies:
  - TASK-62.3
parent_task_id: TASK-62
type: feature
ordinal: 133000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The visible payoff. A dossier is currently a wall of generic file-type icons, and a freshly uploaded document that is still being scanned is indistinguishable from one that is ready until the user clicks it and is refused with no explanation.

Scanning is asynchronous and usually quick, so the scanning state must resolve on its own. The application already broadcasts over Reverb for transcription and pipeline updates; follow that.

Copy goes through the co-located per-domain translations, and missing copy is loud by design (TASK-53).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Document lists and cards show the thumbnail where one exists and fall back to the existing file-type icon where it does not
- [x] #2 Scanning, ready and blocked are visually distinct, with a pulsing skeleton while scanning
- [x] #3 A document being scanned resolves to ready or blocked without a page reload
- [x] #4 A blocked document explains why rather than failing silently when opened
- [x] #5 All new copy is translated in every supported language
- [ ] #6 The thumbnail and its fallback behave at 375px width
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. A DocumentScanState enum deriving three interface states from GuardDutys five, so the raw vocabulary stays server-side.
2. Expose scan_state and thumbnail_url on StaffUploadedDocumentData and regenerate the TypeScript.
3. DocumentThumbnail with a file-type icon fallback, a skeleton while checking, and an onError fallback.
4. DocumentScanBadge, silent when ready.
5. Replace the download with an explanation when a file is blocked.
6. An Echo hook reloading the dossier prop on the verdict, gated on something actually being in flight.
7. Copy in both locales, then component and hook tests.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
The interface deliberately speaks a narrower vocabulary than the scanner. DocumentScanState has three cases where MalwareScanStatus has five: a detection, a file the scanner could not read, a permissions failure and an outright error are four different operational facts and one identical user-facing one -- the file will not open. A test asserts the blocked label never leaks "threat", "malware", "virus" or the vendor name, per the no-stack-leakage rule in .ai/rules/customer-facing-copy.md.

DocumentScanState::fromScanStatus short-circuits to Ready when malware scanning is not enforced. Without that, local and CI -- where nothing ever writes a verdict -- would show every document stuck on "running security check" forever.

The thumbnail falls back to the file-type icon three ways: no URL, feature disabled, and an image that fails to load. The last one is not defensive padding -- a preview can be withdrawn between the page rendering and the image loading, by a delete in another tab or a re-scan that found a threat, and a broken image in a list is worse than an icon.

The reload hook is gated on something actually being in flight. The Echo channel is the whole firm, so an ungated hook would reload the page for every colleague uploading anything, anywhere.

Updating the shared UploadedDocument test fixture was not optional bookkeeping: it uses `as UploadedDocument`, so the new fields compiled fine and arrived undefined at runtime, which crashed five existing panel tests inside the translator. Worth knowing that this cast hides payload drift rather than catching it.

AC#6 is not checked. Verifying 375px needs the browser plugin, and although pestphp/pest-plugin-browser is a dependency, Playwrights browser binaries are not installed in the container -- the run fails with PlaywrightOutdatedException. Installing them downloads browser binaries, so it was left for the author to approve rather than done unilaterally. The layout is built for it (a shrink-0 48px box inside an existing flex-wrap row), but that is reading CSS, not evidence.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Documents now show a rendered first page instead of a generic icon, and an honest security-check state that resolves on its own.

DocumentScanState maps GuardDutys five verdicts onto the three things a reader needs: running, ready, blocked. DocumentThumbnail shows the preview with the existing file-type icon as its fallback, a skeleton while the check runs, and the icon again if the image fails to load. A blocked file replaces its download with an explanation rather than offering a button the server would refuse with a 403.

Verified: 715 frontend tests passing (up from 711), including 12 new ones across the badge, the thumbnail and the Echo hook; the hook test pins the broadcast name against DocumentScanStateChanged::broadcastAs(), where a typo would fail silently by subscribing successfully and never firing. tsc, oxlint and the formatter are clean, and both locales are covered -- LanguageCoverageTest is what caught all five new keys as missing.

AC#6 (375px) is left unchecked. It needs the browser plugin, and although the Composer package is installed, Playwrights browser binaries are not present in the container. Installing them downloads browser binaries, so the decision was left to the author. Everything else here rests on tests rather than on reading the markup.

One decision worth a second opinion, recorded on TASK-62.3: the thumbnail endpoint is deliberately not behind RequirePassword, because an <img> in a list cannot follow a redirect to a password page. A member whose confirmation has lapsed therefore sees a 400x560 render of page one. The download ability is still required and cross-firm access 404s, but that is a real, if small, relaxation of the document-access control.
<!-- SECTION:FINAL_SUMMARY:END -->
