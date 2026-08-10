---
paths:
  - 'app/{DocumentProcessing,AudioProcessing,Shared}/**'
---

# Document Processing Audio Processing Shared

## No Shared Closure-bag async provider runners
OCR/transcription narrative stays in domain: linear orchestrators, Fail*/Complete*/Mark* Actions, and Transitions for claim/complete/fail. Shared must not grow multi-callback start/fail/claim façades. Driver factories and SQS consume commands stay domain-local (SNS vs EventBridge differ). Documented in docs/architecture.md under Async provider processing.
