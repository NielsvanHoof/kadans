---
id: decision-3
title: Accept static IAM credentials for local development for v1.0
date: '2026-08-11 10:05'
status: accepted
---
## Context

Local development still uses static IAM user keys. The permission set is narrowed into
one shared document (`infra/src/policies.ts`) used by both the local IAM user and an
ECS task role already provisioned in `infra/src/application.ts`. Application code already
skips explicit credentials when `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` are empty.
CI authenticates via GitHub OIDC with no stored keys.

## Decision

Accept static IAM credentials for local development for v1.0. This is narrowed debt,
blocked on application compute (decision-2), not an oversight.

## Consequences

- **Reopen trigger:** when application compute lands in Pulumi (decision-2) — then
  remove local long-lived keys from the default path and document the task-role /
  OIDC-only flow.
- Residual risk is limited to developer machines and should not appear in production
  once the ECS task role path is used.
