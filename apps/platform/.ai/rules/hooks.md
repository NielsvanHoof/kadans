---
paths:
  - 'resources/js/features/**/hooks/**'
---

# Hooks

## Live reloads use Echo, not usePoll
Async job completion on live Inertia pages uses Echo on `workspaces.{slug}` + `router.reload({ only: [...] })`, not usePoll. Leading-dot event names must match broadcastAs(). Data-subject exports use `.data-subject.export.completed`; pipelines use `.pipeline.updated`.
