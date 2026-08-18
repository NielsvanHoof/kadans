---
id: TASK-28.1
title: Add Psalm taint analysis for PHP SAST
status: To Do
assignee:
  - cursor-agent
created_date: '2026-08-11 09:38'
updated_date: '2026-08-11 10:00'
labels: []
milestone: m-0
dependencies:
  - TASK-28
references:
  - .github/workflows/platform.yml
  - composer.json
  - phpstan.neon
  - tools/psalm/composer.json
  - bin/psalm-taint
  - psalm.xml
  - 'https://github.com/psalm/psalm-plugin-laravel'
documentation:
  - docs/security.md
  - 'https://psalm.dev/docs/security_analysis/'
modified_files:
  - .github/workflows/platform.yml
  - apps/platform/psalm.xml
  - apps/platform/bin/psalm-taint
  - apps/platform/tools/psalm/composer.json
  - apps/platform/tools/psalm/composer.lock
  - apps/platform/composer.json
  - apps/platform/.gitignore
  - apps/platform/docs/security.md
  - >-
    apps/platform/app/Dossiers/Actions/AudioRecordings/UploadAudioRecordingAction.php
  - >-
    apps/platform/app/Dossiers/Actions/DocumentRequests/UploadDocumentForRequestAction.php
parent_task_id: TASK-28
priority: medium
type: enhancement
ordinal: 81100
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
CodeQL covers JavaScript/TypeScript only; PHP is unsupported. The Laravel backend still has no security-oriented static analysis for injection-class defects (PHPStan and composer audit are not substitutes).

Add PHP taint analysis so untrusted input flowing into dangerous sinks is flagged automatically, with results visible alongside the existing CodeQL/Trivy alerts. Keep PHPStan as the type checker.

Context from TASK-28: Platform CI lives in `.github/workflows/platform.yml`; supply-chain narrative and open gaps live in `docs/security.md` (currently gap “No PHP SAST”).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 PHP application code is scanned for taint-based security defects on pull requests and on main
- [ ] #2 Findings are visible as GitHub code-scanning alerts on the PR
- [ ] #3 Whether findings fail the CI job is an explicit project choice (not left at a silent tool default), and that choice is documented
- [ ] #4 docs/security.md describes the PHP SAST gate and no longer lists “No PHP SAST” as an open gap
- [ ] #5 PHPStan remains the type-analysis gate; this work does not replace it
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Decisions (final)
- Use official **psalm/plugin-laravel** ^4.15 with Psalm 7 (beta), not hand-rolled stubs.
- Isolate toolchain in `tools/psalm/` (own composer.lock) — root app cannot install Psalm with PHPUnit 13 (`sebastian/diff` ^9 vs Psalm ^8).
- Keep PHPStan as the only type gate; suppress Psalm 7 annotation aids (`MissingPureAnnotation`, etc.) so the job is taint-focused.
- CI: `psalm-taint` job uploads SARIF then fails on error-level findings (explicit; Psalm may exit 0 when reporting in Actions).
- Fix real taint findings rather than baselining when cheap (upload filename extension).

## Delivered
1. tools/psalm + psalm.xml + bin/psalm-taint + composer script `psalm:taint`
2. platform.yml job + deployment-artifact dependency
3. Upload actions use putFile(); docs/security.md updated
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Composer cannot install vimeo/psalm alongside PHPUnit 13: Psalm caps sebastian/diff at ^8, PHPUnit needs ^9. Removed mistaken 0.3.14 resolve. Approach: commit psalm.xml; run Psalm via PHAR / setup-php tools:psalm:6.16.1 (verified on PHP 8.5).

User asked to use official psalm/plugin-laravel (https://github.com/psalm/psalm-plugin-laravel) instead of hand-rolled Request stubs. Investigating install: 4.x needs Psalm 7-beta; composer still hits sebastian/diff ^8 vs PHPUnit 13 ^9.

Switched to official psalm/plugin-laravel ^4.15 + Psalm 7.0.0-beta19 in tools/psalm (isolated lockfile). Custom Request stubs removed.

First clean plugin run found 2 TaintedFile issues (client extension → putFileAs); fixed with Storage putFile()/hashName. Annotation noise suppressed in psalm.xml; Tainted* still errors.

Verify: ./bin/psalm-taint clean; 11 related feature tests passed.

User asked to move from 7.0.0-beta19 to Psalm master (7.x-dev) now that sebastian/diff ^9 is merged; attempting to collapse tools/psalm into the root Composer lock.

User deferred: removed all Psalm tooling from the tree. Kept putFile() upload fixes. Restored No PHP SAST open gap in docs/security.md. TASK returned to To Do for a later attempt.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Deferred
Psalm PHP SAST was attempted then **removed** from the tree. Keep the upload `putFile()` hardening from the taint findings.

### Why deferred
- `7.0.0-beta19` cannot install next to PHPUnit 13 (`sebastian/diff` ^9).
- `dev-master` (with ^9) installs but crashes on PHP 8.5 (Revolt fork / dynamic-property deprecation).

### Removed
`psalm.xml`, `bin/psalm-taint`, `tools/psalm/`, `psalm:taint` script, `psalm-taint` CI job.

### Docs
`docs/security.md` again lists **No PHP SAST** as an open gap with the above notes.

Revisit when a tagged Psalm release works with PHPUnit 13 + PHP 8.5.
<!-- SECTION:FINAL_SUMMARY:END -->
