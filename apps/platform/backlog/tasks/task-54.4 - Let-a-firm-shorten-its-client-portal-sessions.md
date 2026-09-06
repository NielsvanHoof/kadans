---
id: TASK-54.4
title: Let a firm shorten its client portal sessions
status: To Do
assignee: []
created_date: '2026-09-04 20:37'
labels: []
dependencies:
  - TASK-54.1
parent_task_id: TASK-54
priority: medium
type: feature
ordinal: 116000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The portal session window is one pair of numbers for every firm on the platform: PORTAL_SESSION_IDLE_MINUTES (15) and PORTAL_SESSION_ABSOLUTE_MINUTES (240). A firm handling more sensitive client material cannot ask for a tighter window without a redeploy that would tighten it for everyone.

Under TASK-54s stricter-only rule a firm may only shorten. That matters beyond policy: ProductionConfigurationValidator refuses to boot production when the idle window is at or above session.lifetime, and because a firm can only shorten, that boot guarantee keeps covering every firm with no change to the validator.

Two traps found while planning:

PortalSessionWindow::open runs at portal.exchange, which sits outside the ResolveClientPortalGrant middleware group on purpose (routes/portal.php). Tenant::current() is null there, while extend() runs inside the group where it is not. The per-firm lookup has to key off the grants tenant_id, which both paths already have.

The bounds cannot live in App\ClientPortal: tests/Pest.php asserts App\Tenancy must not use App\ClientPortal, and the MIN/MAX constants are private. They belong in Tenancy, which ClientPortal may import freely.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A firm administrator can shorten the portal idle and absolute session windows, or leave them inherited
- [ ] #2 A window longer than the platform value is rejected on save and clamped on read
- [ ] #3 A shortened window applies from the moment a client opens their link, not only on later requests
- [ ] #4 The absolute window can never be shorter than one idle window
<!-- AC:END -->
