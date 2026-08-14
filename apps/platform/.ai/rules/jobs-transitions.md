---
paths:
  - 'app/{DocumentProcessing,AudioProcessing}/{Jobs,Transitions}/**'
---

# Jobs Transitions

## A retryable provider failure must reset the subject, not leave it claimed
Both async pipelines reset their subject in the retry branch before rethrowing: `AudioTranscriptionTransitions::resetForRetry()` and `UploadedDocumentProcessingTransitions::resetForRetry()`. Keep them symmetric — the document one was missing, and that was a bug, not a design difference (TASK-36.13).

Why it matters: `claim()` treats "status = Processing AND provider job id is set" as *an external job is already in flight* and returns null. So a failure that reached the provider before dying left a subject no later attempt could claim. Those attempts logged "could not claim" and returned **successfully**, meaning the exhausted-failure branch never ran — no error text, no `*Failed` audit event, and a review card that spins forever. Nothing recovers it: there is no scheduled OCR/transcription sweep, and `StuckPipelinesCheck` counts `Pipeline` rows, not `processing_status` / `transcription_status`.

The reset is guarded on the expected path so a job holding a replaced upload revision cannot reset the current one.

Accepted cost: the original provider job is orphaned and its completion no longer matches the new job id, so the provider is paid twice. That is deliberate — a duplicate charge beats a document that never finishes.

This is per-domain duplication on purpose. Do NOT introduce a shared base class or Shared façade to reconcile the two; see .ai/rules/document-processing-audio-processing.md.
