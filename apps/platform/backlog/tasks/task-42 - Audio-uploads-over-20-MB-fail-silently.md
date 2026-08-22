---
id: TASK-42
title: Audio uploads over 20 MB fail silently
status: Done
assignee: []
created_date: '2026-08-21 12:53'
updated_date: '2026-08-21 13:56'
labels:
  - audio
  - uploads
  - infra
  - frontend
dependencies: []
modified_files:
  - docker/8.5/Caddyfile
  - docker/staging/php.ini
  - docker/production/php.ini
  - app/Dossiers/Http/Requests/AudioRecordings/StoreAudioRecordingRequest.php
  - resources/js/features/dossiers/show/dossier-show-deferred-sections.tsx
  - resources/js/features/dossiers/show/audio-recordings-card.tsx
priority: high
type: bug
ordinal: 95000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Uploading an audio recording between 20 MB and 25 MB silently does nothing: HTTP 302, no row in `audio_recordings`, no log line, and no visible error.

Two independent defects stack:

1. **PHP drops the file.** The FrankenPHP web SAPI runs with `upload_max_filesize = 20M`, set by `php_ini upload_max_filesize 20M` in `docker/8.5/Caddyfile` (which silently overrides the 100M in `docker/8.5/php.ini`) and by `upload_max_filesize=20M` in `docker/staging/php.ini` and `docker/production/php.ini`. `StoreAudioRecordingRequest` validates `->max('25mb')`, so 20-25 MB is a dead zone where the app promises success and PHP refuses. Because `post_max_size` is 210M the body still parses (no 413, CSRF intact); PHP just stamps the $_FILES entry with UPLOAD_ERR_INI_SIZE, so validation fails on `uploaded` and `UploadAudioRecordingAction` is never reached. Validation failures are not report()ed, hence empty logs. Reproduces in staging and production, not just locally.

2. **The error message is never rendered.** `AudioRecordingUploadForm` (and its `useForm` state) sits inside `<Deferred data="audio_recordings">` in `dossier-show-deferred-sections.tsx`. `back()` is a fresh Inertia visit whose page object re-declares `audio_recordings` as deferred, so `<Deferred>` swaps in the skeleton, unmounts the form, and discards `errors.audio` before it can paint. This hides *every* validation failure on this form, not just oversized files.

Decision: raise the ceiling to 25M rather than lowering the app to 20 MB, because audio recordings are legitimately large. `post_max_size` stays 210M (documents remain capped at 20 MB x 10 = 200 MB).

The WAF references to "20 MB" in `infra/src/shared/edge.ts` and `infra/src/stack.production.test.ts` are explanatory docblocks; `SizeRestrictions_BODY` is overridden to `count`, so nothing there enforces a limit.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 upload_max_filesize is 25M in docker/8.5/Caddyfile, docker/staging/php.ini and docker/production/php.ini
- [x] #2 The audio per-file ceiling is a single named constant shared by the FormRequest and its validation message, not a hardcoded '25mb' string
- [x] #3 A test fails if any environment's upload_max_filesize drops below the audio or document per-file validation ceiling
- [x] #4 A 21.85 MiB MP4 uploads successfully end to end: row created, file stored, transcription pipeline started
- [x] #5 A validation error on the audio upload form stays visible after the back() redirect instead of being unmounted by the deferred boundary
- [x] #6 A read-only user with no recordings still sees no audio card
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
## Root cause chain (both halves verified mechanically)

**PHP layer.** Probed the running FrankenPHP worker over HTTP: it reported `upload_max_filesize = 20M` while `sail php -r` reported 100M, because the Caddyfile `php_ini` directive wins for the web SAPI and `docker/8.5/php.ini` is only ever read by the CLI. That is why the mismatch was invisible to every `artisan`/CLI check. With `post_max_size` at 210M the body still parsed (no 413, CSRF intact), so PHP only poisoned the one $_FILES entry with UPLOAD_ERR_INI_SIZE.

**Frontend layer.** Read `@inertiajs/react`'s dist to confirm the mechanism rather than guess:
- `<Deferred>` is a *pure render gate* — it shows `fallback` whenever `propsAreDefined` is false. It does not issue the request; Inertia core does that from the page object's `deferredProps`.
- `useForm`'s `onError` is gated on `isMounted.current`.
- `router.post` already defaults to `preserveState: true`, so the *page* component never remounted.

So the only thing unmounting the form was the `<Deferred>` boundary around it: the back() redirect re-declares `audio_recordings` as deferred, the boundary swapped in the skeleton, `isMounted.current` went false, and `onError` dropped the errors on the floor. Narrowing the boundary to the list alone is therefore both necessary and sufficient.

## Decisions

- Raised the ceiling to 25M rather than lowering the app to 20 MB (user's call). `post_max_size` stays 210M: documents are still validated at 20 MB x 10 = 200 MB.
- Added `AudioUploadLimits` mirroring `DocumentUploadLimits`, whose docblock already required ini/validation sync. Audio had skipped that convention with a bare `'25mb'`, which is what allowed the drift.
- Rewrote (did not delete) `ContainerImageLayoutTest`'s 'upload limits stay in step' test: it hardcoded `upload_max_filesize=20M`, recreating the same manual-sync hazard. It now asserts the two deployed envs cannot drift from each other and reads the values instead of hardcoding them; the absolute ceiling-vs-validation invariant moved to the new UploadLimitsTest, which also covers the local Caddyfile the old test never checked.
- Commented the Caddyfile override trap in place, since `sail php -r` actively misleads on it.

## Verification

- `UploadLimitsTest`: 6 pass. Negative-controlled — reverting the Caddyfile to 20M fails it with 'Failed asserting that 20971520 is ... greater than 26214400'.
- `StoreAudioRecordingValidationTest`: 4 pass, including a new HTTP test posting a 22_374 KB `speech.mp4 (540p).mp4` that asserts no session errors, a row, stored bytes and a pipeline.
- Real SAPI check: raw multipart POST of the actual 22_910_565-byte file to a temporary probe returned `error = 0 (UPLOAD_ERR_OK)`, full size, tmp_name present. Probe deleted.
- `dossier-show-deferred-sections.test.tsx` (4) + `audio-recordings-card.test.tsx` (2). Negative-controlled — against the pre-fix file exactly one fails ('keeps the audio card mounted while the recordings prop is still deferred'), while the three preserved-behaviour cases including AC #6 pass on both.
- Full suites: 1385 PHP / 504 Vitest / 69 infra all pass. Pint clean, `tsc --noEmit` exit 0, no codegen churn.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Two stacked defects made 20-25 MB audio uploads a silent no-op.

**PHP dropped the file.** Raised `upload_max_filesize` from 20M to 25M in `docker/8.5/Caddyfile` (whose `php_ini` directive overrides `docker/8.5/php.ini` for the web SAPI — the CLI reports the other value, which is why it never showed up in `artisan`), `docker/staging/php.ini` and `docker/production/php.ini`. Below the validation ceiling PHP fails the part with UPLOAD_ERR_INI_SIZE while `post_max_size` still admits the body, so there is no 413 and no exception — just a failed `uploaded` rule and an unreported validation error.

**The error was never rendered.** The upload form sat inside `<Deferred data="audio_recordings">`; the back() redirect re-defers that prop, so the boundary unmounted the form and `useForm`'s `onError` (gated on `isMounted.current`) discarded the errors. Narrowed the boundary to the recordings list, so the card and its form stay mounted; `AudioRecordingsSection` keeps the read-only-and-empty case out of the layout.

Also added `AudioUploadLimits` so the ceiling is one named constant rather than a bare `'25mb'`, and fixed the stale 20 MB references in the php.ini comments, `DocumentUploadLimits`, `infra/src/shared/edge.ts`, `infra/src/stack.production.test.ts` and `infra/README.md`.

Verified: new `UploadLimitsTest` guards ceiling >= validation in all three environments and is negative-controlled (fails on the reverted 20M); a new HTTP test uploads a 22_374 KB `speech.mp4 (540p).mp4` successfully; a raw multipart POST of the real 22_910_565-byte file through FrankenPHP now returns UPLOAD_ERR_OK; two new Vitest suites pin the boundary placement and are negative-controlled against the pre-fix component. Full suites pass: 1385 PHP, 504 Vitest, 69 infra.
<!-- SECTION:FINAL_SUMMARY:END -->
