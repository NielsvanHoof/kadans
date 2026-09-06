---
id: TASK-54
title: Give firms per-workspace policy settings
status: To Do
assignee: []
created_date: '2026-09-04 20:37'
labels: []
dependencies: []
priority: medium
type: feature
ordinal: 112000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
tenant_settings today holds three booleans — the AI feature opt-outs from commit 2abee2b0. Every other policy a firm might reasonably decide for itself is a platform-wide env() value in config/security.php, config/portal.php and config/retention.php: one number for every firm on the platform.

For an accounting and advisory platform that is the wrong shape. A firm holding Dutch client financial records has its own position on how long archived client data is kept, whether every staff member must hold a second factor, and how long a client portal session may idle. None of that is expressible without a redeploy, and WorkspacePermission::ManageSettings currently unlocks a page with three checkboxes on it.

Decisions taken with the user before this task was opened, so a future agent does not relitigate them:
- Wide nullable columns on tenant_settings, not EAV. NULL means "inherit the platform config value".
- Stricter only: a firm may tighten past the platform config, never loosen it. This is what keeps ProductionConfigurationValidator honest — it refuses to boot production when 2FA is off or when the portal idle window is at or above session.lifetime, and those guarantees stay true for every firm transitively only if overrides can never weaken.
- Retention is the exception: shorter is stricter for privacy, longer for a statutory retention obligation, so it is a two-sided [min, max] band rather than a one-sided clamp.
- Firm rename changes both name and slug; old workspace URLs stop working and the copy must say so.
- Retention scope is archived_days and activity_log_days only; the other eight knobs stay platform ops.

Subtasks carry the per-area work. This parent tracks the feature as a whole.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A firm administrator can set firm identity, 2FA enforcement, portal session lifetime, reminder defaults and the retention window from the firm settings page
- [ ] #2 A setting left unset inherits the platform config value, and the UI shows which value is inherited
- [ ] #3 A firm cannot save a value weaker than the platform config, and a value weakened directly in the database is clamped on read
- [ ] #4 Every new endpoint has happy-path, forbidden-role and owner-vs-read-only authorization coverage
- [ ] #5 EN and NL copy stay aligned via lang:organize
<!-- AC:END -->
