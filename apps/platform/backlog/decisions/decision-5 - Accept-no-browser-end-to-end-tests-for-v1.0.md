---
id: decision-5
title: Accept no browser end-to-end tests for v1.0
date: '2026-08-11 10:05'
status: accepted
---
## Context

Frontend unit coverage is enforced as a Vitest line ratchet (`vitest.config.ts`).
TASK-14.4 removed dead Playwright/Pest browser scaffolding rather than writing smoke
tests. The client portal path an unauthenticated user touches is covered at the HTTP
feature level in `tests/Feature/Portal/` (token exchange, session requirement, upload,
cross-dossier/tenant rejection, answers, quotas, audit rollback) but not as a
real-browser walkthrough.

## Decision

Accept no browser E2E suite for v1.0. Feature/HTTP coverage plus Vitest is the gate.

## Consequences

- **Reopen trigger:** before the first real client document enters the system if portal
  UX regressions become a release risk, or when a dedicated browser CI budget is
  approved (Pest browser / Playwright).
- Risk: layout/JS interaction bugs can ship despite green feature tests.
