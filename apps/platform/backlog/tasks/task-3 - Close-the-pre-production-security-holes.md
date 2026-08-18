---
id: TASK-3
title: Close the pre-production security holes
status: Done
assignee: []
created_date: '2026-08-06 09:50'
updated_date: '2026-08-06 11:09'
labels: []
dependencies: []
documentation:
  - docs/security.md
  - docs/architecture.md
priority: high
type: bug
ordinal: 8000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
A security audit of the platform found four high-severity, externally reachable weaknesses plus three medium ones. None are in the tenancy core, the policy layer or the portal grant design, which all held up under attack. They cluster in edge/infra trust boundaries, response headers and rate limiting.

Each subtask is independently shippable and independently verifiable. Where a subtask changes production configuration it must also extend `ProductionConfigurationValidator`, which already fails application boot on 20 config keys - that is the existing mechanism for making this class of regression impossible, so extend it rather than adding a comment.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
All subtasks 3.1–3.7 marked Done. Closing parent.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
All seven independently shippable subtasks are Done: trusted hosts (3.1), password-reset rate limits (3.2), Reverb hardening (3.3), S3 Content-Disposition escaping (3.4), /health gated to platform support (3.5), trusted proxies pinned off REMOTE_ADDR (3.6), and production Caddy no longer overwriting portal Referrer-Policy (3.7).
<!-- SECTION:FINAL_SUMMARY:END -->
