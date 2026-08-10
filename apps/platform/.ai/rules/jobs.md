---
paths:
  - 'app/*/Jobs/**'
---

# Jobs

## Queue jobs declare tries/timeout/backoff and a self-releasing unique lock
Every job carries `#[Tries]`, `#[Timeout]`, and `#[Backoff]` attributes backed by class constants, plus `ShouldBeUnique`.

`uniqueFor()` must be `(TIMEOUT × MAX_TRIES) + array_sum(BACKOFF)` so a SIGKILL or OOM cannot wedge the subject forever — see `ProcessUploadedDocumentJob`.

Jobs take scalar IDs, not Eloquent models, and implement `tags()` for Telescope/Horizon searchability.

Dispatch **after** the `DB::transaction()` closure returns, not inside it — no queue connection sets `after_commit`, so an in-transaction dispatch can race the commit.
