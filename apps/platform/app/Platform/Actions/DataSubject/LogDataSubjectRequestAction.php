<?php

declare(strict_types=1);

namespace App\Platform\Actions\DataSubject;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Clients\Models\Client;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Models\DataSubjectRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record that a client has exercised an access or erasure right.
 *
 * Logging is separate from fulfilling it: the regulation gives a month to
 * respond, and erasure is irreversible, so a firm needs the request on record —
 * with a due date — before anything is acted on.
 */
final readonly class LogDataSubjectRequestAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(
        Client $client,
        User $requestedBy,
        DataSubjectRequestType $type,
    ): DataSubjectRequest {
        $this->guardAgainstDuplicate($client, $type);

        return DB::transaction(function () use ($client, $requestedBy, $type): DataSubjectRequest {
            $requestedAt = now();

            $request = DataSubjectRequest::query()->create([
                'tenant_id' => $client->tenant_id,
                'client_id' => $client->id,
                'type' => $type,
                'status' => DataSubjectRequestStatus::Pending,
                'requested_at' => $requestedAt,
                'due_at' => $requestedAt->copy()->addDays(
                    Config::integer('retention.data_subject_response_days'),
                ),
                'requested_by' => $requestedBy->id,
            ]);

            $this->logAuditActivity->handle(
                AuditEvent::DataSubjectRequestLogged,
                $request,
                [
                    'client_id' => $client->id,
                    'request_type' => $type->value,
                    'due_at' => $request->due_at->toIso8601String(),
                ],
                causer: $requestedBy,
            );

            return $request;
        });
    }

    /**
     * One open request per client per type. A second one would create two due
     * dates for the same obligation and two artefacts to keep track of.
     */
    private function guardAgainstDuplicate(Client $client, DataSubjectRequestType $type): void
    {
        $exists = DataSubjectRequest::query()
            ->where('client_id', $client->id)
            ->where('type', $type->value)
            ->where('status', DataSubjectRequestStatus::Pending->value)
            ->toBase()
            ->exists();

        throw_if($exists, ValidationException::withMessages([
            'type' => __('This client already has an open request of that kind.'),
        ]));
    }
}
