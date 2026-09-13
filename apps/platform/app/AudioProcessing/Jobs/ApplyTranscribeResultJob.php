<?php

declare(strict_types=1);

namespace App\AudioProcessing\Jobs;

use App\AudioProcessing\Actions\CompleteTranscribeJobAction;
use App\AudioProcessing\Actions\FailTranscribeJobAction;
use App\Shared\Contracts\Queue\TimeBoundedJob;
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
 * Applies one Amazon Transcribe completion, off the SQS poll loop.
 *
 * The mirror of ApplyTextractResultJob, and deliberately a separate class
 * rather than a shared base: the EventBridge and SNS envelopes genuinely
 * differ, and .ai/rules/document-processing-audio-processing.md is explicit
 * that these two narratives stay linear in their own domains.
 *
 * `transcribe:consume` used to fetch and map the transcript inline, so one long
 * recording held up every other tenant's transcription. The command now parses
 * and dispatches; the work happens here, where it can run concurrently and
 * retry with backoff.
 *
 * Tenant-aware queues are on, so the tenant current at dispatch rides in the
 * payload and is restored before handle() — the same arrangement as OCR.
 */
#[Backoff(self::BACKOFF_SECONDS)]
#[Tries(self::MAX_TRIES)]
#[Timeout(self::TIMEOUT_SECONDS)]
final class ApplyTranscribeResultJob implements ShouldBeUnique, ShouldQueue, TimeBoundedJob
{
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    /** Fetching the transcript from S3 and mapping speaker-labelled segments. */
    public const int TIMEOUT_SECONDS = 180;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $notification  The EventBridge `detail` object.
     */
    public function __construct(
        public array $notification,
        public string $transcribeJobId,
    ) {
        $this->onQueue('transcription');
    }

    public static function timeoutSeconds(): int
    {
        return self::TIMEOUT_SECONDS;
    }

    public static function lifecycleSeconds(): int
    {
        return (self::timeoutSeconds() * self::MAX_TRIES) + array_sum(self::BACKOFF_SECONDS);
    }

    /**
     * EventBridge delivers at least once, and a redelivery after a consumer
     * crash is normal, so two messages for one job must not both do the work.
     */
    public function uniqueId(): string
    {
        return $this->transcribeJobId;
    }

    /**
     * Auto-release the unique lock after worst-case attempts + backoff so a
     * SIGKILL or OOM cannot wedge the subject forever.
     */
    public function uniqueFor(): int
    {
        return self::lifecycleSeconds();
    }

    /**
     * Telescope / Horizon searchable tags.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'transcription',
            'transcribe_job:'.$this->transcribeJobId,
        ];
    }

    public function handle(CompleteTranscribeJobAction $completeTranscribeJob): void
    {
        $applied = $completeTranscribeJob->handle($this->notification);

        if ($applied) {
            return;
        }

        // A notification the action cannot act on — a non-final status, or a job
        // name no recording claims. Running the same payload again will not
        // change that, so it is logged rather than retried or failed.
        Log::warning('Transcription: Transcribe completion could not be applied.', [
            'transcribe_job_id' => $this->transcribeJobId,
        ]);
    }

    /**
     * Retries are exhausted, so the recording must not sit in `processing`
     * forever. Replaces the consumer's SQS receive-count bookkeeping.
     */
    public function failed(Throwable $exception): void
    {
        resolve(FailTranscribeJobAction::class)->handle($this->transcribeJobId, $exception);
    }
}
