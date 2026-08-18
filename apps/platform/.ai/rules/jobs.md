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

## Heavy processing jobs use dedicated Redis queues
OCR (`ocr`), transcription (`transcription`), and dossier export (`dossier-export`) set `$this->onQueue(...)` in the job constructor so they cannot stall the default queue. `AppServiceProvider::configureDevCommands()` registers a matching `queue:listen --queue=…` for each. Provider completions still arrive via `textract:consume` / `transcribe:consume`, not these Redis queues. Production workers must listen to all four queues (default + the three dedicated ones).
