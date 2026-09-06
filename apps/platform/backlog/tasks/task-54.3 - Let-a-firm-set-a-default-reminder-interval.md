---
id: TASK-54.3
title: Let a firm set a default reminder interval
status: To Do
assignee: []
created_date: '2026-09-04 20:37'
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
- [ ] #1 A firm administrator can set a default reminder interval, or leave it unset
- [ ] #2 A new dossier starts with the firm default prefilled
- [ ] #3 Clearing the interval on a dossier still means no reminders, on create and on edit
- [ ] #4 Editing an existing dossier does not re-enable reminders that were turned off
- [ ] #5 The interval bounds are declared once rather than in each dossier form request
<!-- AC:END -->
