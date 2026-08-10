<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\Actions\DataSubject\GenerateDataSubjectExportAction;
use App\Platform\Models\DataSubjectRequest;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Build a data-subject export off the request cycle.
 *
 * No tenant handling here: `multitenancy.queues_are_tenant_aware_by_default` is
 * on, so the tenant current at dispatch is stored in the payload and restored
 * before this runs — the same arrangement the OCR, transcription, and dossier
 * export jobs rely on. A scoped query is therefore correct and safe.
 *
 * Generous timeout: the archive can contain every document and recording ever
 * uploaded for one client, each read from S3.
 */
#[Backoff(self::BACKOFF_SECONDS)]
#[Tries(self::MAX_TRIES)]
#[Timeout(self::TIMEOUT_SECONDS)]
final class GenerateDataSubjectExportJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    public const int TIMEOUT_SECONDS = 600;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [30, 120, 300];

    public function __construct(public int $dataSubjectRequestId) {}

    public function uniqueId(): string
    {
        return (string) $this->dataSubjectRequestId;
    }

    /**
     * Auto-release the unique lock after worst-case attempts + backoff so a
     * SIGKILL or OOM cannot wedge the request forever.
     */
    public function uniqueFor(): int
    {
        return (self::TIMEOUT_SECONDS * self::MAX_TRIES) + array_sum(self::BACKOFF_SECONDS);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'data-subject-export',
            'data_subject_request:'.$this->dataSubjectRequestId,
        ];
    }

    public function handle(GenerateDataSubjectExportAction $generateDataSubjectExport): void
    {
        $request = DataSubjectRequest::query()
            ->whereKey($this->dataSubjectRequestId)
            ->first();

        // Deleted, or belonging to another tenant, between dispatch and running.
        if (! $request instanceof DataSubjectRequest) {
            Log::warning('Data subject: export job found no matching request.', [
                'data_subject_request_id' => $this->dataSubjectRequestId,
            ]);

            return;
        }

        $generateDataSubjectExport->handle($request);
    }
}
