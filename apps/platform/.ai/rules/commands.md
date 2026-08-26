---
paths:
  - 'app/{DocumentProcessing,AudioProcessing}/Console/Commands/**'
---

# Commands

## Consume commands switch tenant before dispatch
textract:consume / transcribe:consume are landlord daemons. Apply* jobs are tenant-aware (`queues_are_tenant_aware_by_default`). After verifying the envelope, look up tenant_id by provider job id (`queryWithoutTenantScope`) and dispatch inside `TenantContext::runForTenant`. Dispatching with no tenant puts no tenantId on the payload; Spatie then deletes the job (`CurrentTenantCouldNotBeDeterminedInTenantAwareJob`) and OCR/transcription never complete. The lookup is the only query this loop is allowed — block pagination and Bedrock stay on the ocr/transcription queues.
