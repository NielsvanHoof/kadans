<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Jobs\Ocr;

use App\DocumentProcessing\Actions\Ocr\CompleteTextractExtractionAction;
use App\DocumentProcessing\Actions\Ocr\FailTextractExtractionAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

use function array_sum;

/**
 * Applies one Textract completion notification, off the SQS poll loop.
 *
 * `textract:consume` used to do this work inline: for each message it switched
 * tenant, paginated every block of the document into one PHP array, and made a
 * synchronous Bedrock call to normalise the overview. That is a minute of work
 * on a large document, performed single-file in a process shared by every
 * tenant, so one slow document stalled everyone else's OCR. It also meant a
 * batch of five messages was processed serially inside one SQS visibility
 * window, so the later ones could reappear while still being worked.
 *
 * The command now only parses, verifies and dispatches; the expensive part
 * happens here, where the queue can run several at once and retry with backoff.
 *
 * Tenant-aware queues are on, so the tenant current at dispatch rides in the
 * payload and is restored before handle() — the same arrangement
 * ProcessUploadedDocumentJob relies on. A scoped query is therefore correct.
 *
 * Timeout is per document rather than per batch, which is what makes the
 * visibility window and the work it covers the same size again.
 */
#[Backoff(self::BACKOFF_SECONDS)]
#[Tries(self::MAX_TRIES)]
#[Timeout(self::TIMEOUT_SECONDS)]
final class ApplyTextractResultJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    /**
     * Block pagination plus one Bedrock call (itself capped by
     * ai.agents.document_overview.timeout), with headroom for a large document.
     */
    public const int TIMEOUT_SECONDS = 180;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [10, 30, 60];

    /**
     * @param  array{JobId?: mixed, Status?: mixed, StatusMessage?: mixed, API?: mixed}  $notification
     *                                                                                                  The verified inner SNS payload. Scalars only — the command has already
     *                                                                                                  checked the envelope signature, so nothing unverified is queued.
     */
    public function __construct(
        public array $notification,
        public string $textractJobId,
    ) {
        $this->onQueue('ocr');
    }

    /**
     * SNS delivers at least once, and a redelivery after a consumer crash is
     * normal, so two messages for one Textract job must not both do the work.
     */
    public function uniqueId(): string
    {
        return $this->textractJobId;
    }

    /**
     * Auto-release the unique lock after worst-case attempts + backoff so a
     * SIGKILL or OOM cannot wedge the subject forever.
     */
    public function uniqueFor(): int
    {
        return (self::TIMEOUT_SECONDS * self::MAX_TRIES) + array_sum(self::BACKOFF_SECONDS);
    }

    /**
     * Telescope / Horizon searchable tags.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'ocr',
            'textract_job:'.$this->textractJobId,
        ];
    }

    public function handle(CompleteTextractExtractionAction $completeTextractExtraction): void
    {
        $applied = $completeTextractExtraction->handle($this->notification);

        if ($applied) {
            return;
        }

        // The action refuses a notification it cannot act on: an unexpected API,
        // a non-final status, or a job id no document claims. None of those
        // change by running the same payload again, so this is a dead end, not
        // a retry — and it is logged rather than thrown so it does not occupy
        // the failed-jobs table alongside real failures.
        Log::warning('OCR: Textract completion could not be applied.', [
            'textract_job_id' => $this->textractJobId,
            'status' => $this->notification['Status'] ?? null,
        ]);
    }

    /**
     * Retries are exhausted, so the document must not sit in `processing`
     * forever. This is the terminal-failure path the consumer used to reach by
     * counting SQS receives — now driven by the job's own attempt budget, with
     * backoff between attempts and a failed_jobs row to show for it.
     */
    public function failed(Throwable $exception): void
    {
        resolve(FailTextractExtractionAction::class)->handle($this->textractJobId, $exception);
    }
}
