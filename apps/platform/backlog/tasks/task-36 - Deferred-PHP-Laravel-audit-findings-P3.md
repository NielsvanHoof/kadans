---
id: TASK-36
title: Deferred PHP/Laravel audit findings (P3)
status: Done
assignee: []
created_date: '2026-08-13 11:33'
updated_date: '2026-08-14 23:01'
labels:
  - audit
dependencies: []
references:
  - ~/.claude/plans/https-laravel-com-docs-13-x-helpers-intr-nested-brooks.md
  - TASK-35
priority: medium
type: task
ordinal: 15000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Real findings from the PHP/Laravel audit, deliberately deferred from the surgical P1/P2 pass (TASK-35). Each is worth a follow-up; none is safe to bundle into that change set.

Do not "fix" the confirmed-healthy areas listed in the audit: jobs-in-transactions, tenant-scope escape hatches, mass assignment, route-binding scoping, swallowed exceptions, raw SQL, withCount, column projection on large indexes, activity_log index coverage, Octane state, cache invalidation, Contracts bindings, final classes, AuditEvent exhaustive matches, or setPermissionsTeamId leakage.

Do not introduce shared abstractions between DocumentProcessing and AudioProcessing.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Each deferred finding is tracked as its own subtask with enough context for an independent agent
- [x] #2 None of these is bundled into the P1/P2 surgical pass (TASK-35)
- [x] #3 No DocumentProcessing/AudioProcessing shared pipeline is proposed
<!-- AC:END -->

## Comments

<!-- COMMENTS:BEGIN -->
author: audit-intake
created: 2026-08-13 11:35
---
P3 grouping: concurrency 36.1–36.3; privacy 36.4–36.5; performance 36.6–36.9; config 36.11; architecture 36.10, 36.12–36.14. DocumentProcessing/AudioProcessing shared pipeline is explicitly out of scope. TASK-9.1 already moved TranscriptionStatus.
---
<!-- COMMENTS:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
All 14 subtasks are Done: 36.1 in the earlier pass, 36.2-36.14 in this one.

Concurrency and GDPR (36.2-36.5): the portal byte cap moved to write time inside the transaction that already holds the dossier lock; anonymization and its sibling refusal became single locked transitions behind a new DataSubjectRequestTransitions; client filenames left the application log at seven sites, enforced by a source scanner and a full-OCR-run assertion; staff audio deletion became transactional, audited and fail-safe with a new AuditEvent case.

Performance (36.6-36.9): last_accessed_at writes throttled to a five-minute window at zero query cost; the DSR index moved onto PaginatedIndexQuery and both client pickers were bounded; per-row DELETE/UPDATE loops inside held locks were batched; both export archives now stream instead of retaining every file until close().

Config and architecture (36.10-36.14): document delivery fails closed on missing disk or bucket config; upload-delete eligibility was decided and centralised with the asymmetry recorded as a rule; configuration subscripting collapsed onto model accessors; the retry-reset spike resolved as a bug and fixed; and the Transcribe completion parsing gained a typed DTO that removed a live divergence.

Scope held: no shared DocumentProcessing/AudioProcessing abstraction was introduced (AC#3) and none of this was folded into TASK-35 (AC#2). Three decisions were recorded with Boost record-rule for the next reader, in .ai/rules/transitions-policies-http-controllers.md and .ai/rules/jobs-transitions.md.

Two findings beyond the filed scope were surfaced and handled: RefuseDataSubjectRequestAction carried the identical unlocked guard as 36.3 and was fixed alongside it with the user's approval, and CompleteTextractExtractionAction was leaking a filename that manual review had classified as an audit call.

Verified: full suite 1260 tests / 6956 assertions and 428 vitest tests pass; coverage 88.5% against the 87% floor; PHPStan level 8, Rector, Pint, Biome and tsc all clean. Every fix was confirmed non-vacuous by reintroducing its defect and watching the test go red — a check that caught five of my own tests passing for the wrong reason.
<!-- SECTION:FINAL_SUMMARY:END -->
