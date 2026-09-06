---
id: TASK-54.3
title: Let a firm set a default reminder interval
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-04 20:37'
updated_date: '2026-09-06 11:02'
labels: []
dependencies:
  - TASK-54.1
parent_task_id: TASK-54
priority: medium
type: feature
ordinal: 115000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Every dossier sets its own reminder interval from scratch. A firm with a house rhythm — chase every seven days — retypes it on every dossier, and a staff member who forgets leaves the dossier with no reminders at all.

The interval is a nullable per-dossier column (dossiers.reminder_interval_days) and the 1..30 bounds are duplicated in StoreDossierRequest and UpdateDossierRequest.

The distinction that matters: an absent field means "the firm has not said", while an explicitly empty field means "no reminders on this dossier". The default must fill the first and never override the second, or editing a dossier with reminders deliberately off would silently switch them back on. dossier-follow-up-fields.tsx is shared between the create and edit forms, which is where that mistake would happen.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A firm administrator can set a default reminder interval, or leave it unset
- [x] #2 A new dossier starts with the firm default prefilled
- [x] #3 Clearing the interval on a dossier still means no reminders, on create and on edit
- [x] #4 Editing an existing dossier does not re-enable reminders that were turned off
- [x] #5 The interval bounds are declared once rather than in each dossier form request
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Both dossier Form Requests read their interval rule from FirmSettingBounds::reminderIntervalDays()->rules() instead of spelling out min:1|max:30 twice (AC #5). Dossiers is L4 and Tenancy L2, so this is a legal downward dependency.
2. StoreDossierRequest::prepareForValidation() fills reminder_interval_days from the firm default ONLY when the key is absent from the payload. Absent means "the firm has not said"; an explicitly empty field means "no reminders on this dossier" and must survive untouched. Update deliberately does not do this, or editing a dossier with reminders off would switch them back on (AC #3, #4).
3. create-dossier-form.tsx passes defaultReminderIntervalDays from the shared prop via useDefaultReminderIntervalDays(). DossierFollowUpFields already takes that prop; the edit form passes the dossier own value, so it is untouched (AC #2).
4. Tests. PHP: a new dossier with the key omitted picks up the firm default; with the key present but empty it stays null; updating a dossier that has no interval leaves it null; a value outside the band is rejected. Vitest: the create form prefills the firm default, and the edit form shows the dossier own value rather than the firm one.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Most of this was already standing after TASK-54.1: the column, the bounds, the Data object, the shared prop slice and the Reminders tab all landed with the spine. What was left was the consumption side.

Both dossier Form Requests now read FirmSettingBounds::reminderIntervalDays()->rules() instead of spelling out min:1|max:30 twice (AC #5). Dossiers is L4 and Tenancy L2, so the dependency points downward and the arch test is satisfied.

StoreDossierRequest::prepareForValidation() fills the interval from the firm default ONLY when the key is absent. UpdateDossierRequest deliberately does not, and carries a comment saying why: an edit that omits the field must not switch reminders back on for a dossier whose author turned them off.

DossierFollowUpFields already accepted defaultReminderIntervalDays and the edit form already passed the dossier own value — only the create form needed wiring, which is what keeps create and edit asymmetric in the right direction.

One deliberate non-change: the min="1" max="30" attributes on the interval input stay hardcoded. Sourcing them from the shared prop would mean every test rendering DossierFollowUpFields needs Inertia page context, and the browser attributes are a hint while the server is authoritative and now single-sourced. Not worth the coupling for a client-side hint.

Also renamed three test helpers I had left too generic in 54.1/54.3 (createDossier, setFirmDefault, probeValues, countingQueries). Pest helper functions are global, and the repo convention is to suffix them with the test purpose (createDossierForAudioLifecycleTest) so a later file cannot collide with a fatal redeclare.

Validation: pest tests/Feature/Dossiers/FirmReminderDefaultTest.php => 7 passed, covering absent vs empty vs explicit, no firm default, edit not re-enabling, and both ends of the band. Wider run: Dossiers/Tenancy/Unit/Architecture/Localization => 565 passed; ClientPortal/Questionnaires/Platform/Reporting/Retention => 431 passed. npm run test => 664 passed (124 files). tsc, vp check and pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A firm can set a house reminder rhythm, and new dossiers start with it.

The interval bounds now come from FirmSettingBounds in both dossier Form Requests rather than being written out twice, and StoreDossierRequest falls back to the firm default only when the caller omitted the field entirely. An explicitly empty field still means no reminders, and the update path has no fallback at all so editing a dossier cannot switch reminders back on.

Verified with 7 targeted tests covering the absent/empty/explicit distinction in both directions, plus two frontend tests pinning that the shared follow-up fields prefill the firm rhythm on create and stay empty when nothing is passed — the create/edit asymmetry is the whole risk here. 565 backend tests across the affected suites, 431 in consumer domains, 664 frontend, with tsc, vp check and pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
