---
paths:
  - 'app/Platform/Ai/**'
---

# Ai

## ConversationStore stays the tenant-aware wrapper
Laravel AI's DatabaseConversationStore writes via DB::table(), so BelongsToTenant never fires. Bind TenantAwareDatabaseConversationStore over ConversationStore in PlatformServiceProvider. Never rebind the vendor class. Do not cache tenant_id on the singleton — Octane reuses it. UUID v7 string ids stay because the SDK returns them as conversationId.

## Bedrock Anthropic access is two gates; the second returns 404
A Bedrock Anthropic call can fail with `404 Not Found` and "Model use case details have not been submitted for this account" even when IAM is correct. That is the account-level Bedrock model-access gate, not permissions: submit the Anthropic use-case form in the AWS Console (Bedrock → Model access) per account and region.

Signature: ~1s per attempt, so a job with tries=3 and backoff [10,30,60] burns its whole budget in ~44s. Hit staging 2026-08-27 via DocumentOverviewAgent in ApplyTextractResultJob. Textract has no equivalent gate, so the OCR submit step stays green and only the overview step fails.

Anthropic is now disallowed in three places rather than merely unused, because the 404 is too easy to misread: `infra/src/config.ts` (defaultBedrockModelArns) withholds the IAM grant, `ProductionConfigurationValidator::DISALLOWED_AI_MODEL_FRAGMENTS` fails boot on an `anthropic.` model id in a shipped environment, and every agent runs Amazon Nova (`eu.amazon.nova-pro-v1:0` for document_overview, Nova Lite as the provider default). Re-enabling once the submission clears means re-adding the foundation-model ARN and clearing that fragment list — do both or neither.
