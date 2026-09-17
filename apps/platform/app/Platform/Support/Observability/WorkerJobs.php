<?php

declare(strict_types=1);

namespace App\Platform\Support\Observability;

use App\AudioProcessing\Jobs\ApplyTranscribeResultJob;
use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\DocumentProcessing\Jobs\Ocr\ApplyTextractResultJob;
use App\DocumentProcessing\Jobs\Ocr\ProcessUploadedDocumentJob;
use App\Dossiers\Jobs\Exports\GenerateDossierExportJob;
use App\Dossiers\Jobs\Scanning\ApplyScanResultJob;
use App\Platform\Jobs\DataSubject\AnonymizeDataSubjectJob;
use App\Platform\Jobs\DataSubject\GenerateDataSubjectExportJob;
use App\Shared\Contracts\Queue\TimeBoundedJob;

/**
 * Every queued job the application dispatches, as {@see TimeBoundedJob}.
 *
 * The sibling of {@see WorkerQueues}, and it exists for the same reason: a list
 * that something load-bearing reads must be one list, guarded against the
 * filesystem, not several copies that drift.
 *
 * The copies this replaced had drifted. `ProductionConfigurationValidator`
 * named four of these seven when deciding whether `retry_after` clears the
 * longest job timeout — omitting `AnonymizeDataSubjectJob`, whose 600s happened
 * to equal a job that was listed, so the answer was right by luck rather than
 * by construction. `UniqueJobLockExpiryTest` named three, leaving the 2250s
 * erasure lock — the longest-lived in the system — unasserted.
 *
 * `WorkerJobCoverageTest` fails when a job under `app/*Jobs/` is missing here,
 * so the next job added cannot quietly weaken either guard.
 */
final class WorkerJobs
{
    /**
     * @var list<class-string<TimeBoundedJob>>
     */
    public const array NAMES = [
        ProcessUploadedDocumentJob::class,
        ApplyTextractResultJob::class,
        ProcessAudioRecordingJob::class,
        ApplyTranscribeResultJob::class,
        ApplyScanResultJob::class,
        GenerateDossierExportJob::class,
        GenerateDataSubjectExportJob::class,
        AnonymizeDataSubjectJob::class,
    ];

    /**
     * The longest a single attempt of any job may run.
     *
     * `queue.connections.redis.retry_after` must exceed this. Redis re-reserves
     * a job once retry_after elapses and `ShouldBeUnique` does not prevent that
     * — it only blocks new dispatches — so a retry_after at or below this runs
     * a GDPR erasure twice, concurrently.
     */
    public static function longestTimeoutSeconds(): int
    {
        $timeouts = array_map(
            static fn (string $job): int => $job::timeoutSeconds(),
            self::NAMES,
        );

        return max($timeouts);
    }
}
