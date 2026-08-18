---
id: TASK-29
title: Convert the open security gaps into accepted-risk records for v1.0
status: Done
assignee:
  - cursor-agent
created_date: '2026-08-08 13:36'
updated_date: '2026-08-11 10:08'
labels: []
milestone: m-0
dependencies: []
documentation:
  - docs/security.md
priority: medium
type: chore
ordinal: 82000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
This is the task that closes the v1.0 scope and stops the gap list reading as an unfinished backlog.

`docs/security.md` ends with eight numbered "Open gaps". After TASK-22 through TASK-28, the ones that remain are all deliberate deferrals rather than oversights, but they are still written as debt. For a code-complete release the honest framing is an accepted risk with a rationale and a trigger condition, not an open item — a reader should be able to tell "we decided this" apart from "we did not get to this".

The ones to convert, each needing a stated reason it is acceptable now and a named condition that would change the answer:

- 2FA is available but not enforced for staff.
- No malware scanning on uploads: clients upload arbitrary files that staff then open in a browser. The compensating controls already in place are the type allowlist validated by real MIME, the per-file CSP with `script-src none`, and delivery from a separate S3 origin.
- No GDPR data-subject export or erasure endpoint; scheduled retention purging exists, on-demand fulfilment does not.
- Portal grant tokens are re-exchangeable until expiry, with no device or IP binding and no new-device notification. The existing rationale (lost emails, multi-device use, support burden of single-use links) is already written and should be preserved.
- No application compute in the Pulumi stack, and therefore no app deploy path.
- Static IAM credentials for local development, narrowed to one shared policy document but not eliminated, blocked on the item above.

The natural trigger for most of these is the same sentence and it should be stated plainly: before the first real client document enters the system. That is what makes the deferral defensible rather than convenient.

Consider `backlog decision` records for these rather than tasks, so they leave the task list entirely and stop competing for attention with work that is actually queued.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Each remaining gap is recorded as an accepted risk with a rationale and an explicit trigger condition that would reopen it
- [x] #2 docs/security.md distinguishes accepted risks from open work, so a reader can tell a decision from an omission
- [x] #3 The existing rationale for re-exchangeable portal links is preserved rather than rewritten
- [x] #4 No item remains that is neither tracked as a task nor recorded as an accepted risk
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Map current docs/security.md Open gaps (post TASK-22–28) against the stale task description — drop already-closed items (2FA enforced, GDPR export/erasure) and keep portal Known tradeoff text.
2. Create backlog decision records (status accepted) for each deliberate deferral: malware scanning, no app compute in Pulumi, static local IAM, re-exchangeable portal grants, no browser E2E, incomplete support-session expiry audit.
3. Leave No PHP SAST as tracked open work pointing at TASK-28.1 (not an accepted risk).
4. Rewrite docs/security.md: Accepted risks (with rationale + reopen trigger + decision IDs) vs Remaining tracked work; keep portal Known tradeoff section intact.
5. Fill decision Context/Decision/Consequences bodies (CLI only creates shells).
6. Check AC and finalize.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Mapped current gaps vs stale task text: 2FA and GDPR export/erasure already closed; added browser E2E + support-session audit as decisions; left PHP SAST as TASK-28.1.

Filled decision bodies by editing decision markdown (CLI create only supports title/status).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Converted the remaining `docs/security.md` open gaps into accepted-risk records for v1.0 so they stop reading as unfinished backlog.

## Changes
- Created accepted backlog decisions **decision-1** through **decision-6** (malware scanning, no app compute in Pulumi, static local IAM, re-exchangeable portal grants, no browser E2E, incomplete support-session expiry audit), each with rationale and an explicit reopen trigger (typically before the first real client document).
- Rewrote the end of `docs/security.md`: **Accepted risks (v1.0)** table vs **Remaining tracked work** (No PHP SAST → TASK-28.1).
- Preserved the Client portal **Known tradeoff** wording unchanged.
- Noted that stale description items (unenforced 2FA, missing GDPR export/erasure) are already closed and are not accepted risks.

## Verification
- Decision files present under `backlog/decisions/` with Context / Decision / Consequences.
- `docs/security.md` distinguishes decisions from tracked work; portal Known tradeoff section intact.
- No remaining former gap sits outside a decision or TASK-28.1.
<!-- SECTION:FINAL_SUMMARY:END -->
