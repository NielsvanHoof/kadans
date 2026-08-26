---
id: decision-11
title: Run inference on AWS Bedrock in-region and pin the provider at boot
date: '2026-08-25 21:44'
status: accepted
---
## Context

Three features send workspace data to a model, and `docs/security.md` — which
documents the S3 KMS key and the retention schedule in detail — said nothing about
any of them. TASK-27 was raised to close that, on the premise that the data went to
OpenAI: `config/ai.php` sets `'default' => 'openai'`.

That premise was wrong, and the way it was wrong is the useful part. Every agent
overrides the global default in its own `ai.agents.*` slot, and all three default to
`bedrock`. `ResolvesConfiguredModel::provider()` reads the agent slot first and falls
back to `ai.default` only when that slot is blank — which it never is. So `ai.default`
was unreachable configuration naming a provider that was never used and never even
credentialed (`OPENAI_API_KEY` is absent from `.env.example`).

Reading the top-level default and stopping there is an easy mistake to make twice,
which is why this record states the resolution order explicitly.

The exposure is also larger than TASK-27 described. It named two flows carrying
metadata — client name, dossier title, item titles, and a staff-typed description.
The third, `DocumentOverviewAgent`, classifies OCR extractions from *"payslips, bank
statements, invoices, ID cards, tax forms"* and receives every extracted field as
`label: value`. It is bound unconditionally in `DocumentProcessingServiceProvider`,
with no flag and no per-workspace opt-in.

## Decision

Keep inference on AWS Bedrock in-region, disclose all three flows, and pin the
provider so it cannot drift.

Bedrock resolves its region through `AWS_BEDROCK_REGION` → `AWS_DEFAULT_REGION` →
`eu-west-1`, and the document overview agent pins
`eu.anthropic.claude-sonnet-4-20250514-v1:0`, whose `eu.` prefix is a cross-region
inference profile confined to EU regions. It authenticates with the same IAM key as
S3, Textract and Transcribe (decision-10), and `policies.ts` already grants it. The
sub-processor is therefore AWS — already named in the DPA — rather than a new vendor
with its own transfer analysis.

Two changes back the decision:

1. `ai.default` becomes `bedrock`. The fallback stops being a trapdoor to a provider
   nobody chose and becomes the provider we did choose.
2. `ProductionConfigurationValidator` rejects boot when any agent resolves to a
   provider outside `APPROVED_AI_PROVIDERS` (`['bedrock']`). It resolves agent-slot-
   then-fallback, exactly as `ResolvesConfiguredModel` does, so it catches a bad slot
   and a bad fallback reached through a blank slot.

## Consequences

- **The failure mode is now degraded-to-correct.** Blanking an `AI_*_PROVIDER` env
  var used to reroute client document fields silently; it now either lands on
  Bedrock or fails boot. Consistent with how the OCR driver, transcription driver,
  mailer and Sentry DSNs are already guarded — a misconfiguration cannot reach
  traffic.
- **Adding a provider is now a deliberate act.** A new one must be added to
  `APPROVED_AI_PROVIDERS`, which is the point: that list is a sub-processor
  allowlist, and extending it is a DPA question before it is a code question.
- **Document field values still leave the process.** Pinning the provider does not
  minimise what is sent. `buildContext()` already minimises tables to shape only,
  and the same argument would apply to fields — the agent classifies a document and
  may not need every value to do it. Not taken now because it changes model
  behaviour and needs its own evaluation.
- **No per-workspace opt-in.** A firm cannot decline the document overview agent
  today. Acceptable while the provider is the same AWS account and region as the
  documents themselves; not acceptable if inference ever moves to a third party.
- **Reopen trigger:** any proposal to move inference off Bedrock or outside the EU,
  or the first customer DPA that asks for AI processing to be switchable per firm.
