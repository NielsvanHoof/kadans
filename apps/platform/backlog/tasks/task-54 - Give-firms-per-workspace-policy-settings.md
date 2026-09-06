---
id: TASK-54
title: Give firms per-workspace policy settings
status: Done
assignee: []
created_date: '2026-09-04 20:37'
updated_date: '2026-09-06 15:11'
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
- [x] #1 A firm administrator can set firm identity, 2FA enforcement, portal session lifetime, reminder defaults and the retention window from the firm settings page
- [x] #2 A setting left unset inherits the platform config value, and the UI shows which value is inherited
- [x] #3 A firm cannot save a value weaker than the platform config, and a value weakened directly in the database is clamped on read
- [x] #4 Every new endpoint has happy-path, forbidden-role and owner-vs-read-only authorization coverage
- [x] #5 EN and NL copy stay aligned via lang:organize
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
All six subtasks complete. Full suite run before closing: vendor/bin/sail artisan test --compact => 1739 passed, 13620 assertions, exit 0.

Evidence per criterion:
#1 All five areas save from the firm settings page, which is now tabbed (Details, Signing in, Client portal, Reminders, Retention, Assisted features). Covered by WorkspaceSettingsTest, FirmRenameTest, FirmReminderDefaultTest, FirmPortalSessionWindowTest, FirmRetentionWindowTest, FirmTwoFactorEnforcementTest.
#2 Null means inherit throughout. FirmSettingData ships override, effective and platform_default so the field can sit empty while still naming the value in force; asserted in FirmSettingBoundsTest and inherited-number-field.test.tsx.
#3 Rejected on save AND clamped on read, for every setting. The read-time clamp is exercised through the page props, the portal window, and a deliberately hostile TwoFactorEnforcementSource.
#4 The one genuinely new endpoint, workspaces.settings.firm.update, has owner and administrator happy paths and a role dataset covering adviser, reviewer and read-only. The settings endpoint keeps its existing owner/administrator/adviser coverage.
#5 lang:organize --check reports up to date, en/nl parity holds, and the pinned dictionary size moved 1416 -> 1463 in step.

Three defects were found and fixed by testing rather than by reading, all recorded on their subtasks: a renamed firm serving its old name from a reused Octane worker (makeCurrent short-circuits on a matching key); an inherited platform value reaching the portal unclamped; and that same clamp being wrong for a banded setting, which stopped a short operator retention window from ever pruning.

Two conventions were recorded for the next reader: .ai/rules/settings.md (bounds declared once, stricter-only, clamped on read) and an addition to .ai/rules/shared-props.md (keep firm_settings small) and .ai/rules/tenancy.md (mutating a tenant row needs an explicit rebind).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Firms now decide their own policy: firm identity, two-factor enforcement, client portal session length, reminder defaults and the retention window, all from a tabbed firm settings page behind the ManageSettings permission that previously unlocked three checkboxes.

The spine is a bounds layer that declares each setting once and drives both the validation rules and a read-time clamp, so the two cannot drift; a cached, read-only resolver that never writes or aborts and is safe to call from middleware, the portal and console sweeps; and a shared prop deliberately trimmed to what non-admin UI needs. Null means inherit the platform value, and a firm may only be stricter - which is what keeps ProductionConfigurationValidator guaranteeing production never runs with two-factor off or an oversized portal window, for every firm, without becoming tenant-aware. Retention is the deliberate exception, a two-sided band, because shorter is stricter for privacy and longer for a statutory obligation; its default moved from three years to seven with the user.

Two-factor needed the only layering inversion: Accounts owns the contract, Tenancy implements it, and the middleware applies the clamp itself so no implementation can weaken the floor.

Verified with the full suite: 1739 tests, 13620 assertions, exit 0, plus 664 frontend tests, tsc, vp check, pint and lang:organize --check all clean. docs/security.md was updated for both the retention schedule and the two-factor floor, since that document is the record a firm would be shown.
<!-- SECTION:FINAL_SUMMARY:END -->
