---
id: TASK-55
title: Group oversized flat domain folders into subfolders
status: To Do
assignee: []
created_date: '2026-09-06 11:06'
labels: []
dependencies:
  - TASK-54
priority: medium
type: chore
ordinal: 119000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Several domain folders have grown past the point where the file list tells you anything. app/Dossiers/Data holds 18 classes covering the builder, the review flow, index and archive rows, exports, upload tickets, document requests and audio recordings, all in one flat list — finding the right one means reading most of them.

The pattern to follow already exists in the codebase: app/Dossiers/Actions is split into AudioRecordings, Builder, DocumentRequests and Uploads. This applies the same idea where it has become warranted, rather than inventing a new convention.

A scan of app/ for directories holding 10 or more PHP files with no subfolders, done 2026-09-06:

  18  app/Tenancy/Data
  18  app/Dossiers/Data
  17  app/Tenancy/Actions
  16  app/Reporting/Data
  13  app/Dossiers/Http/Controllers
  12  app/Dossiers/Queries
  11  app/Dossiers/Enums
  11  app/ClientPortal/Actions
  10  app/Reporting/Queries
  10  app/Questionnaires/Actions
  10  app/Dossiers/Support

In several of these the class-name prefixes already name the grouping: Reporting/Data is ActivityLog*, Dashboard* and MediaLibrary*; Tenancy/Data is Tenant*/FirmSetting* settings, Workspace* members, roles and invitations; Dossiers/Data groups around the builder, the review, list rows, exports and the per-artifact staff payloads.

THE COST TO KNOW BEFORE STARTING, because it is not visible from the folder listing: every Data class carries #[TypeScript], and the transformer mirrors the PHP namespace. Moving app/Dossiers/Data/DossierBuilderData.php into a Builder/ subfolder renames the generated type from App.Dossiers.Data.DossierBuilderData to App.Dossiers.Data.Builder.DossierBuilderData. There are currently 139 such references across 28 frontend files. This is mechanical but wide, and it is the reason the Data folders are the expensive ones while Actions, Queries, Support and Enums are nearly free.

Worth deciding rather than assuming: whether the generated TypeScript namespace SHOULD gain that extra level, or whether the transformer should be configured to keep the flatter type names it emits today. Splitting the PHP folders while keeping the emitted TS names stable would make this a backend-only change.

Not every folder on the list needs splitting. Ten near-identical Queries are easier to scan than ten Queries in four folders; the ones worth doing are those where the names already fall into obvious groups. Judgement per folder, not a rule applied to the whole list.

Scheduled after TASK-54 so it does not collide with the per-firm settings work still landing in app/Tenancy/Data.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The folders chosen for splitting are the ones whose contents fall into obvious groups, and the reasoning for each folder left alone is recorded
- [ ] #2 The TypeScript namespace question is decided explicitly, and the frontend either compiles against the new names or against deliberately unchanged ones
- [ ] #3 tsc, the full PHP suite, the Vitest suite and the domain layering arch test all pass
- [ ] #4 The Data-under-app/{Domain}/Data convention in .ai/rules/requests-enums-policies.md is updated if subfolders become the expectation
<!-- AC:END -->
