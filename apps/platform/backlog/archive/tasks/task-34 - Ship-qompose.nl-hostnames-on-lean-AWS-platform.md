---
id: TASK-34
title: Ship qompose.nl hostnames on lean AWS platform
status: To Do
assignee: []
created_date: '2026-08-11 16:54'
updated_date: '2026-08-11 18:40'
labels:
  - tenancy
  - infra
  - aws
  - domains
milestone: m-0
dependencies: []
references:
  - 'https://www.qompose.nl'
  - >-
    .cursor/projects/Users-nielsvanhoof-Documents-projects-qompose-apps-platform/canvases/lean-security-extras.canvas.tsx
  - >-
    /Users/nielsvanhoof/.cursor/plans/domain_tenancy_feasibility_9d737075.plan.md
documentation:
  - docs/architecture.md
  - infra/README.md
  - docs/security.md
  - >-
    .cursor/projects/Users-nielsvanhoof-Documents-projects-qompose-apps-platform/canvases/lean-security-extras.canvas.tsx
priority: high
type: feature
ordinal: 900
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Production URL contract and hosting for the platform, greenfield (no live traffic or prod data to preserve).

Hostname model:
- Marketing stays on www.qompose.nl (existing site, out of scope for this app)
- Central platform on app.qompose.nl
- Each firm on {slug}.qompose.nl (sibling subdomains; required)
- Reserve system labels (app, www, mail, api, status, …) so they never become tenant slugs

Hosting follows the Lean + security extras target: ECS Fargate ARM + ALB + RDS Postgres t4g.micro Multi-AZ + Valkey micro×2 + EventBridge schedule, plus the security/ops extras pack instead of Aurora.

Path-based /workspaces/{tenant:slug} tenancy is replaced entirely by host-based resolution — no dual-mode or redirects.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Production hostname model is documented and enforced: www marketing, app central, {slug} tenants, reserved system labels
- [ ] #2 Lean AWS compute + security extras from the Lean + security extras canvas are provisioned enough to serve app.qompose.nl and tenant wildcards
- [ ] #3 Laravel app resolves tenants from subdomain only; /workspaces/{slug} path tenancy is gone
- [ ] #4 Local/dev hostname conventions match the production app + tenant model
- [ ] #5 Feature tests and project docs reflect the host-based model
<!-- AC:END -->

## Comments

<!-- COMMENTS:BEGIN -->
created: 2026-08-11 18:24
---
Current production discovery (Aug 2026): DNS is on TransIP (ns0.transip.net / ns1.transip.nl / ns2.transip.eu). Apex/www point at 141.138.169.252 (Apache marketing). app.qompose.nl is already live as a Nuxt app and CNAMEs to k8s.officebox.nl (OfficeBox). Mail is Microsoft 365 (MX + DKIM to Outlook; SPF includes Outlook and office-box.nl). No CAA records. No *.qompose.nl wildcard today. Cutover needs TransIP DNS access to flip app (+ add wildcard) and ACM validation records — do not export TLS private keys from OfficeBox; AWS ACM issues new certs. Leave www/apex and M365 mail records alone unless SES SPF/DKIM is added carefully.
---

created: 2026-08-11 18:40
---
Recommended execution order (reordinaled): 1) TASK-34.5 host tenancy, 2) TASK-34.6 local hostnames, 3) TASK-34.1 GuardDuty/CloudTrail (can overlap with app work), 4) TASK-34.2 lean compute, 5) TASK-34.3 CloudFront/WAF, 6) TASK-34.4 budgets/alarms/DR.
---
<!-- COMMENTS:END -->
