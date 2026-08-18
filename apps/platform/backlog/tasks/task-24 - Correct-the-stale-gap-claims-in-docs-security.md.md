---
id: TASK-24
title: Correct the stale gap claims in docs/security.md
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-08 13:34'
updated_date: '2026-08-11 08:23'
labels: []
milestone: m-0
dependencies:
  - TASK-22
references:
  - vitest.config.ts
  - tests/Feature/Portal
documentation:
  - docs/security.md
modified_files:
  - docs/security.md
priority: medium
type: bug
ordinal: 77000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The "Open gaps" list in `docs/security.md` no longer matches the codebase. On a repository whose job is to be read, a stale self-criticism is more expensive than a real one — it makes the honest gaps harder to trust.

Three corrections, all verifiable:

Gap 8 is wrong on both halves. It claims "Frontend has no meaningful test coverage and there is no end-to-end test of the client portal flow — the one path an unauthenticated user touches." There are 69 Vitest files and an enforced line ratchet in `vitest.config.ts`, and `tests/Feature/Portal/` covers the portal end to end at the HTTP level: token exchange, the session requirement on every portal route, upload, cross-dossier rejection, cross-tenant rejection, answer submission, the byte quota, and database/storage rollback when the audit write fails. TASK-14.4 separately recorded the decision not to write browser tests. This gap should be deleted and replaced by an accurate statement of what is and is not covered, with the frontend coverage number stated as a ratchet rather than an absence.

Gap 2 understates the deploy situation rather than overstating it. It says "CI builds the image but nothing runs it." More precisely: the image is built with an SBOM and max provenance but pushed to no registry — it lands as a GitHub artifact with 7-day retention — and `deploy.yml` is a manual-dispatch Pulumi run covering the OCR stack only, with no migration or application step.

The tenant-isolation and portal sections claim typed audit events "on view, download, export, and access denial". That becomes true only once TASK-22 lands, hence the dependency.

Scope is documentation only. No behaviour changes.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Gap 8 is removed and replaced with an accurate account of frontend and portal test coverage, citing the enforced ratchet and the feature-level portal flow
- [x] #2 The deploy gap states that the image is pushed to no registry and that the Pulumi workflow covers the OCR stack only
- [x] #3 Every remaining audit claim matches an event actually emitted after TASK-22
- [x] #4 No claim in docs/security.md can be falsified by a grep or a test run
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Eligibility
- Dependency TASK-22 is Done (DocumentViewed / AccessDenied / AudioRecordingUploaded now emit).
- Scope: documentation only (`docs/security.md`).

## Verified facts (research)
- Vitest: 78 `*.test.{ts,tsx}` files under `resources/js`; enforced line ratchet `thresholds.lines: 41` in `vitest.config.ts`.
- Portal HTTP coverage: `tests/Feature/Portal/` (token exchange, session required, upload, cross-dossier/tenant destroy, answers, byte quota, audit-failure rollback, device binding).
- TASK-14.4 decided to strip browser scaffolding rather than write Playwright/Pest browser tests.
- Image CI: `../../.github/workflows/platform.yml` builds OCI with `provenance: mode=max` + `sbom: true`, uploads artifact `retention-days: 7`, no registry push (`outputs: type=oci,dest=...`).
- Deploy: `../../.github/workflows/deploy.yml` is `workflow_dispatch` Pulumi OCR stack only; no migrate/app step.
- Audit threat-table claim is now true post TASK-22 (`DocumentViewed`, download, export events, `AccessDenied`).

## Edits
1. Threat table / Audit section: keep view/download/export/access-denial wording (now accurate); spot-check enumerated events still emit.
2. Supply-chain + Open gap 2: state no registry push, 7-day GitHub artifact, `deploy.yml` OCR-only Pulumi; fix workflow path to `../../.github/workflows/...` (matches `docs/testing.md`).
3. Replace Open gap 6 (stale "no frontend coverage / no portal e2e") with accurate ratchet + portal feature coverage + remaining gap = no browser E2E.
4. Grep-verify no remaining falsifiable claims from this pass.

## Out of scope
- Behaviour / code changes.
- Adding Transcribe to the production-guard paragraph (incomplete, not false; separate polish).
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Edited docs/security.md only. Verified AC #1–#4 via grep/file presence: ratchet 41%, portal feature suite, no-registry + 7-day artifact + OCR-only deploy.yml, AuditEvent emitters for claimed events, workflow paths resolve.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Corrected stale self-criticism in `docs/security.md` so the Open gaps list matches the codebase again.

- Replaced the false "no frontend / no portal e2e" gap with an accurate split: Vitest line ratchet at 41% (`vitest.config.ts`), HTTP feature coverage in `tests/Feature/Portal/`, and the remaining gap as no browser E2E (TASK-14.4 decision).
- Tightened the deploy/compute gap and Supply chain section: OCI image built with SBOM + max provenance, pushed to no registry (7-day GitHub artifact), and [`deploy.yml`](../../.github/workflows/deploy.yml) is OCR-stack Pulumi only.
- Left the threat-table audit claim (view / download / export / access denial) in place — TASK-22 made it true; grepped emitters for `DocumentViewed`, `DocumentDownloaded`, `AccessDenied`, and export events.
- Fixed workflow links to `../../.github/workflows/...` so they resolve from `docs/`.

## Verification
Grep/file checks against `vitest.config.ts`, `tests/Feature/Portal/`, AuditEvent emitters, `platform.yml` retention/provenance/sbom, and `deploy.yml` OCR-only dispatch. Documentation-only change — no behaviour tests.

## Follow-up (optional)
Production-guard paragraph still omits Transcribe (code already requires it); incomplete rather than false.
<!-- SECTION:FINAL_SUMMARY:END -->
