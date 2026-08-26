---
paths:
  - 'app/{DocumentProcessing,AudioProcessing}/Actions/**'
---

# Document Processing Audio Processing Actions

## Complete/Fail provider results assume tenant is current
CompleteTextractExtractionAction / CompleteTranscribeJobAction / Fail* use scoped queries. Tenant rides in the Apply* job payload because the consume command switched before dispatch. Do not reintroduce queryWithoutTenantScope + runForTenant here — that belonged to applying results inside the SQS poll loop.
