---
paths:
  - 'app/Pipelines/**'
---

# Pipelines

## Pipelines: one writer, guarded transitions, no domain imports
**PipelineState (app/Pipelines/Support) is the only writer.** It owns the lock, the transaction and the broadcast for every state change. Never call PipelineTransitions / PipelineStepTransitions directly from a domain — go through PipelineState's named methods (start, complete, skip, fail, stuck, heartbeat, heartbeatStep). It is in Support, not Actions, because it has many operations rather than one handle().

**Transitions guard, actions orchestrate.** Transitions decide what state changes are legal and are idempotent on already-terminal steps, which is what makes replayed SNS/EventBridge callbacks safe. Keep new guards there, not in callers.

**Writes are quiet on purpose.** Pipeline and PipelineStep do not use LogsActivity and every write is saveQuietly(). Steps and heartbeats change far more often than an audit trail should record. Log business-meaningful events explicitly via LogAuditActivityAction from the calling action.

**Pipelines must not import domain models.** Per-type behaviour is inverted through two registries that owning domains populate from their own ServiceProvider::boot(): PipelineRunner (queue the run, reset the subject) and PipelineSubjectPresenter (label, context, dossier id, href, eager loads). Adding a PipelineType means adding both, or the run will not be startable or retryable and its subject will render blank. PipelinePolicy's DossierExport import is the one documented exception, for the download permission gate.

**One runner serves the first attempt and every retry.** StartPipelineAction (create + queue), RetryPipelineAction and CancelPipelineAction all go through PipelineRunner, so no caller constructs a processing job itself — that is what let the start path drift from the retry path. Runners must dispatch `->afterCommit()`, which is what makes StartPipelineAction safe to call inside the transaction that creates the subject.

Step keys are the PipelineStepKey enum, never strings. Heartbeats deliberately do not broadcast.
