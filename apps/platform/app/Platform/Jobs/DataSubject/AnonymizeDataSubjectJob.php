<?php

declare(strict_types=1);

namespace App\Platform\Jobs\DataSubject;

use App\Accounts\Models\User;
use App\Platform\Actions\DataSubject\AnonymizeDataSubjectAction;
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
 * Fulfil an erasure request off the request cycle.
 *
 * Tenant context is restored from the queue payload the same way export jobs are.
 */
#[Backoff(self::BACKOFF_SECONDS)]
#[Tries(self::MAX_TRIES)]
#[Timeout(self::TIMEOUT_SECONDS)]
final class AnonymizeDataSubjectJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    public const int TIMEOUT_SECONDS = 600;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [30, 120, 300];

    public function __construct(
        public int $dataSubjectRequestId,
        public int $fulfilledByUserId,
    ) {
        $this->onQueue('dossier-export');
    }

    public function uniqueId(): string
    {
        return (string) $this->dataSubjectRequestId;
    }

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
            'data-subject-erasure',
            'data_subject_request:'.$this->dataSubjectRequestId,
        ];
    }

    public function handle(AnonymizeDataSubjectAction $anonymizeDataSubject): void
    {
        $request = DataSubjectRequest::query()
            ->whereKey($this->dataSubjectRequestId)
            ->first();

        if (! $request instanceof DataSubjectRequest) {
            Log::warning('Data subject: erasure job found no matching request.', [
                'data_subject_request_id' => $this->dataSubjectRequestId,
            ]);

            return;
        }

        $fulfilledBy = User::query()->whereKey($this->fulfilledByUserId)->first();

        if (! $fulfilledBy instanceof User) {
            Log::warning('Data subject: erasure job found no fulfilling user.', [
                'data_subject_request_id' => $this->dataSubjectRequestId,
                'fulfilled_by_user_id' => $this->fulfilledByUserId,
            ]);

            return;
        }

        $anonymizeDataSubject->handle($request, $fulfilledBy);
    }
}
