---
id: TASK-34.3
title: Add CloudFront edge (optional WAF) for platform hostnames
status: To Do
assignee: []
created_date: '2026-08-11 16:54'
updated_date: '2026-08-11 16:55'
labels:
  - infra
  - aws
  - edge
milestone: m-0
dependencies:
  - TASK-34.2
references:
  - >-
    .cursor/projects/Users-nielsvanhoof-Documents-projects-qompose-apps-platform/canvases/lean-security-extras.canvas.tsx
documentation:
  - infra/README.md
parent_task_id: TASK-34
priority: medium
type: feature
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Edge extras from the Lean + security extras canvas: CloudFront in front of the ALB, with optional AWS managed WAF rules.

Serves app.qompose.nl and *.qompose.nl at the edge (TLS, cache static /build assets where safe, hide origin). Marketing www remains on its existing host.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 CloudFront distribution fronts the platform ALB for app.qompose.nl and *.qompose.nl
- [ ] #2 TLS works for central and tenant hostnames via ACM at the edge
- [ ] #3 Static build assets can be cached at the edge without breaking authenticated app traffic
- [ ] #4 Optional managed WAF rules can be enabled without blocking legitimate staff or portal use
- [ ] #5 DNS cutover path for app and tenant wildcard is documented
- [ ] #6 Infra docs cover CloudFront/WAF configuration and operational notes
<!-- AC:END -->
