---
paths:
  - 'app/Platform/Console/Commands/Retention/**'
---

# Commands Retention

## Prune agent conversations by updated_at
retention:prune-agent-conversations deletes idle conversations by updated_at (so an active thread is not cut). Messages go with them via the composite FK cascade. Chunk with --limit; do not issue one unchunked DELETE.
