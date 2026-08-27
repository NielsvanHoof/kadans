---
id: TASK-27
title: Record the AI provider data-flow decision
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-08-08 13:35'
updated_date: '2026-08-25 21:27'
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
- [x] #1 docs/security.md states which data leaves the platform for inference, to which provider, and on what basis
- [x] #2 The chosen option and the alternatives considered are recorded, so the decision is auditable rather than implicit in a config default
- [x] #3 If the decision changes the default provider or the prompt context, the code and tests match the record
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Scoping correction found during the 2026-08-25 reconciliation: there are THREE AI data flows, not two, and the third is much larger than the two this task describes.

app/DocumentProcessing/Ai/DocumentOverviewAgent.php classifies OCR extractions. Its own instructions name the material: 'Dutch and international financial/identity documents (payslips, bank statements, invoices, ID cards, tax forms).'

AgentDocumentOverviewNormalizer::buildContext() sends every extracted field as 'label: value' — so for a payslip that is employer, employee name and pay figures; for an ID card, name, document number and date of birth. Tables are deliberately minimised to shape only (headers/rows counts), so someone already thought about minimisation here, but the field values go in full. The code even carries the comment 'Context may include PII labels — log length only, never the prompt body', so the sensitivity was understood at the logging layer and never followed through to a sub-processor disclosure.

It is on by default and has no gate: DocumentProcessingServiceProvider binds DocumentOverviewNormalizer to AgentDocumentOverviewNormalizer unconditionally — no config flag, no per-workspace opt-in. With OCR_DRIVER=textract in production, every client document that completes OCR sends its extracted field values to config('ai.default'), which is 'openai'.

This changes the weight of the decision. The two flows originally described (ReminderDraftContextData: client name, dossier title, due date, outstanding item titles; ChecklistDraftAgent: a staff-typed description) are metadata. This one is client financial and identity document content, which is what docs/security.md's opening line calls the thing being protected. Any option chosen for the other two should be evaluated against this one first.

No decision taken here — recorded so the choice is made with the full surface visible.

CORRECTION to the note above, and to this task's own description: the provider is NOT OpenAI.

The description says "config/ai.php sets default => openai" and concludes the context "goes to OpenAI every time". That reads ai.default without reading the per-agent slots. All three agents override it:

  document_overview -> env('AI_DOCUMENT_OVERVIEW_PROVIDER', 'bedrock'), model eu.anthropic.claude-sonnet-4-20250514-v1:0
  checklist_draft   -> env('AI_CHECKLIST_DRAFT_PROVIDER', 'bedrock'), model unset
  dossier_reminder  -> env('AI_DOSSIER_REMINDER_PROVIDER', 'bedrock'), model unset

ResolvesConfiguredModel::provider() reads ai.agents.<key>.provider and falls back to ai.default only when that slot is blank or null — which it never is with these defaults. So ai.default => 'openai' is unreachable dead config on every current path, and OPENAI_API_KEY would have to be set for it to work at all.

What is actually true: inference runs on AWS Bedrock in the same account and region family as the rest of the data plane (region env AWS_BEDROCK_REGION -> AWS_DEFAULT_REGION -> eu-west-1), authenticated with the same credentials as S3/Textract/Transcribe (decision-10), and Bedrock is already granted in infra/src/shared/policies.ts. The document_overview model carries the eu. cross-region inference prefix, so that flow is EU-resident by model choice, not just by region config.

That materially reduces this task's weight — Bedrock is an existing sub-processor we already disclose as AWS, not a new US one. Two things survive the correction and are the real work:

1. The disclosure is still missing. docs/security.md says nothing about data leaving for inference, and three flows do — including document field values from payslips, bank statements and ID cards.
2. ai.default => 'openai' is a live drift hazard. Blank any of the three AI_*_PROVIDER env vars and that agent silently falls back to OpenAI, sending client document fields to a provider nobody decided on. ProductionConfigurationValidator guards every other externally-visible setting and does not guard this one — which is exactly the 'optionally pin the provider' note in the description.

Implemented. Decision: keep inference on Bedrock in-region, disclose all three flows, pin the provider at boot. Recorded as decision-11.

AC#1 — docs/security.md gained an 'AI and inference' section: a table of the three flows and exactly what each sends, the Bedrock region resolution chain (AWS_BEDROCK_REGION -> AWS_DEFAULT_REGION -> eu-west-1), the eu. cross-region inference profile on the document overview model, and the note that authentication is the same IAM key as S3/Textract/Transcribe (decision-10) so the sub-processor is AWS rather than a new vendor. It also records the fields-vs-tables asymmetry in buildContext() — tables minimised to shape, fields sent whole — because that is the part a reader would otherwise have to discover in code. The 'Production configuration guard' section now lists the provider check among the things that fail boot.

AC#2 — decision-11 records the choice, the alternatives, and the corrected premise: this task (and my first read of it) concluded the data went to OpenAI by reading ai.default and stopping there. Every agent overrides it in its own ai.agents.* slot and all three default to bedrock; ai.default was unreachable config naming a provider that was never credentialed (OPENAI_API_KEY is absent from .env.example). The record states the resolution order explicitly so the mistake is not made a third time.

AC#3 — code and tests match the record. config/ai.php default is now 'bedrock', so a blanked agent slot degrades to the chosen provider instead of a trapdoor. ProductionConfigurationValidator::unapprovedAiProviderKey() resolves agent-slot-then-fallback exactly as ResolvesConfiguredModel does and reports the offending key; APPROVED_AI_PROVIDERS is ['bedrock']. Tests: three dataset rows for a bad slot on two agents and a wholesale-replaced ai.agents, plus a dedicated three-case test that a blanked slot is rejected when the fallback is unapproved and accepted when it is not — the blank-slot path being the actual hazard, and the one no test covered before.

Also updated docs/legal/COUNSEL-HANDOFF.md, which listed the AI vendor as a placeholder for counsel to fill. It now names Bedrock/eu-west-1, notes there is no separate AI vendor to add to the sub-processor list, and flags that the document overview flow sends field values from payslips and identity documents so counsel can weigh it against the Art. 9 note in the privacy policy.

Not done, deliberately, and recorded as consequences in decision-11: minimising the fields sent (changes model behaviour, needs its own evaluation) and per-workspace opt-in (acceptable while inference is the same AWS account and region as the documents themselves).

Verified: ProductionConfigurationValidator suite 82/82, phpstan 0 errors, pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Documented all three inference flows in docs/security.md and recorded decision-11: inference stays on AWS Bedrock in eu-west-1, the same sub-processor as the rest of the data plane. Corrected the task's premise that the provider was OpenAI — ai.default was unreachable, per-agent slots all default to bedrock. Made the fallback safe ('bedrock') and pinned the provider in ProductionConfigurationValidator so a blanked env var fails boot instead of silently rerouting client document fields. Verified with 82 validator tests including the blank-slot fallback path.
<!-- SECTION:FINAL_SUMMARY:END -->
