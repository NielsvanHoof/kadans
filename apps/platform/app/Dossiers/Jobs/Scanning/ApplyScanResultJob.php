<?php

declare(strict_types=1);

namespace App\Dossiers\Jobs\Scanning;

use App\Dossiers\Actions\Scanning\ApplyMalwareScanResultAction;
use App\Dossiers\Data\Scanning\ScanResultNotification;
use App\Shared\Contracts\Queue\TimeBoundedJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

use function array_sum;

/**
 * Applies one malware scan verdict, off the SQS poll loop.
 *
 * Follows ApplyTranscribeResultJob: the consumer command parses and dispatches,
 * the work happens here where it can retry with backoff and cannot hold up the
 * next message.
 *
 * This job is short — two indexed lookups and one update — so it stays on the
 * default queue rather than claiming a dedicated one. Putting it on `ocr` or
 * `transcription` would queue a sub-second write behind minutes of Textract
 * mapping, and a verdict that arrives late is a document that goes on saying
 * "scanning…" to the person waiting on it.
 *
 * Tenant-aware queues are on, so the tenant current at dispatch rides in the
 * payload and is restored before handle().
 */
#[Backoff(self::BACKOFF_SECONDS)]
#[Tries(self::MAX_TRIES)]
#[Timeout(self::TIMEOUT_SECONDS)]
final class ApplyScanResultJob implements ShouldBeUnique, ShouldQueue, TimeBoundedJob
{
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    /** Two indexed lookups and an update. */
    public const int TIMEOUT_SECONDS = 30;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [5, 15, 30];

    /**
     * @param  array<string, mixed>  $message  The verdict as the Lambda wrote it.
     */
    public function __construct(
        public array $message,
        public string $objectKey,
    ) {}

    public static function timeoutSeconds(): int
    {
        return self::TIMEOUT_SECONDS;
    }

    public static function lifecycleSeconds(): int
    {
        return (self::timeoutSeconds() * self::MAX_TRIES) + array_sum(self::BACKOFF_SECONDS);
    }

    /**
     * SQS delivers at least once, and GuardDuty itself may re-scan an object, so
     * two messages for one key must not both write.
     */
    public function uniqueId(): string
    {
        return $this->objectKey;
    }

    /**
     * Auto-release the lock after worst-case attempts plus backoff, so a SIGKILL
     * or OOM cannot wedge this key forever.
     */
    public function uniqueFor(): int
    {
        return self::lifecycleSeconds();
    }

    /**
     * Telescope / Horizon searchable tags.
     *
     * The key is not a tag: it contains the tenant and dossier ids and ends in
     * the stored filename, and tags are visible on dashboards that are not
     * tenant-scoped.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['malware-scan'];
    }

    public function handle(ApplyMalwareScanResultAction $applyMalwareScanResult): void
    {
        $applied = $applyMalwareScanResult->handle(
            ScanResultNotification::fromQueueMessage($this->message),
        );

        if ($applied instanceof Model) {
            return;
        }

        /*
         * Nothing in this tenant is stored at that key. Ordinary rather than
         * exceptional: a document deleted between the scan and this message
         * arriving looks exactly like this, and retrying will not bring it back.
         */
        Log::info('Scanning: malware verdict matched no stored file.', [
            'object_key' => $this->objectKey,
        ]);
    }
}
