---
paths:
  - 'app/{DocumentProcessing,AudioProcessing}/**'
---

# Document Processing Audio Processing

## Async provider work stays linear in its own domain — no Shared façades
OCR (Textract) and transcription (Transcribe) share one shape: claim → start driver → complete or fail when the provider finishes (sync for the mock driver, async via SNS/EventBridge → SQS for the real one).

Keep each piece in the owning domain: status transitions in `Transitions/`, start + immediate audit in the domain orchestrator (`OcrOrchestrator`, `TranscriptionOrchestrator`), terminal complete/fail in domain `Actions/`, driver selection in a domain `*DriverFactory`, SQS long-poll + envelope parsing in domain `Console/Commands`.

**Do not** reintroduce a Shared "async provider" façade taking five-plus Closures for start/fail/claim. Shared stays infrastructure-only (pagination, CSP, tenant cache invalidation). Prefer typed domain collaborators, or deliberate duplication of a tiny poll loop, when onboarding another provider — the SNS and EventBridge envelopes genuinely differ.

Both domains may depend on `Pipelines` one-way for run tracking, registering a `PipelineRunner` into the registry rather than Pipelines knowing OCR/transcription specifics.
