---
id: TASK-27
title: Record the AI provider data-flow decision
status: To Do
assignee: []
created_date: '2026-08-08 13:35'
labels: []
milestone: m-0
dependencies: []
references:
  - config/ai.php
  - app/Dossiers/Data/ReminderDraftContextData.php
  - app/Dossiers/Ai/DossierReminderDraftAgent.php
  - app/Questionnaires/Ai/ChecklistDraftAgent.php
documentation:
  - docs/security.md
priority: medium
type: chore
ordinal: 80000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Two features send workspace data to a third-party LLM, and neither the threat model nor the production configuration guard mentions it.

`config/ai.php` sets `default => openai`. `ReminderDraftContextData` carries the client name, dossier title, due date, and the titles of outstanding items; that whole context goes to OpenAI every time a staff member drafts a reminder. `ChecklistDraftAgent` sends a free-text description a staff member typed, which can plausibly contain client specifics.

For an accounting and advisory platform holding Dutch client financial records, an LLM provider is a sub-processor: it needs to appear in the threat model, in the sub-processor list a firm would ask for, and ideally in a residency decision. `docs/security.md` currently documents the S3 KMS key and the retention schedule in detail and says nothing about data leaving for inference.

This task is the decision and its record, not a redesign. Options worth weighing and writing down: keep OpenAI and disclose it; switch the default to a provider with an EU data boundary; make the AI features opt-in per workspace; or drop the reminder-draft context down to the item titles alone. Whichever is chosen, the reasoning belongs in the docs so the next reader does not have to rediscover the question.

Optionally pin the provider in `ProductionConfigurationValidator` so production cannot silently drift onto a different one — cheap, and consistent with how every other externally-visible setting is guarded.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 docs/security.md states which data leaves the platform for inference, to which provider, and on what basis
- [ ] #2 The chosen option and the alternatives considered are recorded, so the decision is auditable rather than implicit in a config default
- [ ] #3 If the decision changes the default provider or the prompt context, the code and tests match the record
<!-- AC:END -->
