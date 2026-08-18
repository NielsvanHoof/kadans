---
paths:
  - 'app/*/Actions/**'
---

# Actions

## Start pipeline runs via StartPipelineAction, never dispatch the job yourself
To kick off a pipeline run, call StartPipelineAction (app/Pipelines/Actions) — it creates the run and queues the type's job through the registered PipelineRunner. Do not pair CreatePipelineAction with your own `dispatch(new SomeProcessingJob(...))`: that is what let the first attempt drift from what RetryPipelineAction queues (different arguments, missing ->afterCommit()).

Call it inside the same transaction that creates the subject. Runners dispatch ->afterCommit(), so a rolled back upload queues nothing — no need to smuggle the pipeline out of the closure with a by-reference out-param.
