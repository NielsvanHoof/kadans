---
paths:
  - 'app/**/Jobs/**'
---

# Jobs

## Queue jobs declare tries/timeout/backoff and a self-releasing unique lock
Every job carries `#[Tries]`, `#[Timeout]`, and `#[Backoff]` attributes backed by class constants, plus `ShouldBeUnique`.

`uniqueFor()` must be `(TIMEOUT × MAX_TRIES) + array_sum(BACKOFF)` so a SIGKILL or OOM cannot wedge the subject forever — see `ProcessUploadedDocumentJob`.

Jobs take scalar IDs, not Eloquent models, and implement `tags()` for Telescope/Horizon searchability.

Dispatch **after** the `DB::transaction()` closure returns, not inside it — no queue connection sets `after_commit`, so an in-transaction dispatch can race the commit.

## Heavy processing jobs use dedicated Redis queues
OCR (`ocr`), transcription (`transcription`), and dossier export (`dossier-export`) set `$this->onQueue(...)` in the job constructor so they cannot stall the default queue. Horizon drains all of `WorkerQueues::NAMES` from `config/horizon.php` (local via `php artisan dev`, Cloud via a single custom background process `php artisan horizon`). Provider completions arrive over SQS at `textract:consume` / `transcribe:consume`, but those commands only verify, switch tenant, and dispatch — the work runs as `ApplyTextractResultJob` / `ApplyTranscribeResultJob` on the same `ocr` and `transcription` queues. The tenant must be current at dispatch (`queues_are_tenant_aware_by_default`); otherwise Spatie deletes the job.

## WorkerQueues is the queue drain contract
Worker queue list lives in App\Platform\Support\Observability\WorkerQueues::NAMES (default first), partitioned into INTERACTIVE (default, broadcasts) and HEAVY (ocr, transcription, dossier-export). `config/horizon.php` has one supervisor per group and they must cover NAMES exactly; `WorkerQueueCoverageTest` fails if a dedicated `onQueue()` literal is missing or Horizon drifts. Laravel Cloud must not run `queue:work` or Managed Queues alongside Horizon — keep `QUEUE_CONNECTION=redis` and the custom process `php artisan horizon` × 1. Leave `textract:consume` / `transcribe:consume` as separate daemons.
