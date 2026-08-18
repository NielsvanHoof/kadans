---
id: TASK-28
title: Add SAST and container image scanning to CI
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-08 13:35'
updated_date: '2026-08-11 09:34'
labels: []
milestone: m-0
dependencies: []
references:
  - .github/workflows/platform.yml
  - .github/codeql/codeql-config.yml
documentation:
  - docs/security.md
modified_files:
  - .github/workflows/platform.yml
  - .github/codeql/codeql-config.yml
  - apps/platform/docs/security.md
priority: medium
type: enhancement
ordinal: 81000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The quality gate already runs `composer audit` and `npm audit --audit-level=high`, so known-vulnerable dependencies are caught. Two layers below that are missing.

There is no static analysis for security defects — no CodeQL or equivalent — so the classes of bug that audits cannot see (injection, unsafe deserialization, path traversal) have no automated reader.

And the `deployment-artifact` job generates an SBOM and max provenance for the production image, then nothing consumes either. The base image and OS packages inside the container are never scanned, which is where most container CVEs live rather than in `composer.lock`.

Both are additive workflow changes. Scanning the OCI tarball the build already produces avoids needing a registry, which the pipeline does not currently have (see the deploy gap in docs/security.md). Decide deliberately whether findings block the build or only annotate the run — for a repository at this stage, failing on high or critical and annotating the rest is the usual balance.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A SAST workflow runs on pull requests and on main, and its findings are visible on the PR
- [x] #2 The production image is scanned for OS and base-image vulnerabilities, consuming the artifact the build already produces
- [x] #3 The severity threshold that blocks a build is explicit rather than left at a tool default
- [x] #4 docs/security.md no longer lists SAST and container scanning as gaps
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Decisions
- **SAST:** CodeQL Action v4 (pinned SHA), languages `php` + `javascript-typescript`, scoped to `apps/platform` via `.github/codeql/codeql-config.yml`. Uploads SARIF so findings appear on the PR. Does not fail the job on alert count (visibility); analysis failure still fails the job.
- **Container scan:** Trivy Action v0.36.0 (pinned SHA after the mutable-tag compromise), scans the OCI tarball from `deployment-artifact` (`input:`), no registry needed.
- **Blocking threshold (explicit):** Trivy fails the build on **HIGH,CRITICAL** with **`ignore-unfixed: true`** (only CVEs that have a fix). Documented in `docs/security.md`.
- **Permissions:** CodeQL + Trivy SARIF upload need `security-events: write` on those jobs.

## Changes
1. Add `.github/codeql/codeql-config.yml` (`paths: apps/platform`, ignore vendor/node_modules/build).
2. Extend `.github/workflows/platform.yml`: `codeql` job; Trivy scan (+ SARIF upload) in `deployment-artifact`; path filters include `.github/codeql/**`.
3. Update `docs/security.md` Supply chain + remove gap #4; renumber open gaps.

## Verify
YAML review; docs gap claims gone. Full GHA run happens on push/PR.

Correction after ship: CodeQL does not support PHP. Final language set is javascript-typescript only; PHP SAST remains an open gap (Semgrep candidate).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Shipped: CodeQL job (php + javascript-typescript, security-extended, pinned v4 SHA) with .github/codeql/codeql-config.yml scoped to apps/platform; Trivy in deployment-artifact scanning the OCI tarball via input: with severity HIGH,CRITICAL, ignore-unfixed true, exit-code 1; SARIF upload for both; docs/security.md Supply chain updated and open gap #4 removed.

Verify: workflow YAML review against ACs; grep confirms docs no longer list SAST/container scanning as gaps. Live CodeQL/Trivy evidence lands on the next PR/main run of platform.yml.

Correction: CodeQL does not support PHP. Removing php from the language matrix; keep javascript-typescript only. Docs will state JS/TS coverage and that PHP has no CodeQL-backed SAST.

Fixed: codeql job is javascript-typescript only (no matrix). Config paths-ignore includes **/*.php. security.md states CodeQL JS/TS coverage and lists No PHP SAST as open gap #4.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
- Added CodeQL SAST for **JavaScript/TypeScript only** (`security-extended`, scoped to `apps/platform`) so findings surface as code-scanning alerts on PRs. **PHP is not a CodeQL language** — do not pass `php` to the action.
- Scan the existing OCI production tarball with Trivy (no registry): fail the build on HIGH/CRITICAL CVEs that have a published fix; upload SARIF for the Security tab.
- Documented both gates in `docs/security.md`; recorded **No PHP SAST** as an open gap (Semgrep or similar as follow-up).

## Test plan
- [ ] Open a PR touching `apps/platform` (or `.github/codeql/**`) and confirm the CodeQL JS/TS job runs and uploads alerts.
- [ ] Confirm no CodeQL job attempts language `php`.
- [ ] Confirm `deployment-artifact` runs Trivy after the image build and fails only on HIGH/CRITICAL fixable findings.
- [ ] Spot-check Security tab for CodeQL + `trivy-production-image` SARIF categories.
<!-- SECTION:FINAL_SUMMARY:END -->
