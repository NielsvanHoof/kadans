<?php

declare(strict_types=1);

namespace App\Platform\Actions\DataSubject;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Transitions\DataSubject\DataSubjectRequestTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Close a request without fulfilling it, with a reason on record.
 *
 * Refusal is a legitimate answer — a firm may be unable to erase records it is
 * legally obliged to keep — but it has to be justified and auditable, which is
 * why the reason is required rather than optional.
 */
final readonly class RefuseDataSubjectRequestAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private DataSubjectRequestTransitions $transitions,
    ) {}

    public function handle(
        DataSubjectRequest $request,
        User $refusedBy,
        string $reason,
    ): DataSubjectRequest {
        // Cheap pre-checks. The status one is not the guard — see lockIfOpen().
        throw_unless($this->transitions->canClose($request), ValidationException::withMessages([
            'status' => __('Only an open request can be refused.'),
        ]));

        throw_if(mb_trim($reason) === '', ValidationException::withMessages([
            'refusal_reason' => __('A refusal must record why.'),
        ]));

        return DB::transaction(function () use ($request, $refusedBy, $reason): DataSubjectRequest {
            // Without the lock a refusal could race a fulfil and both would
            // win, leaving one request recorded as Completed and Refused.
            $locked = $this->transitions->lockIfOpen($request);

            throw_unless($locked instanceof DataSubjectRequest, ValidationException::withMessages([
                'status' => __('Only an open request can be refused.'),
            ]));

            $this->transitions->refuse($locked, $refusedBy, $reason);

            $this->logAuditActivity->handle(
                AuditEvent::DataSubjectRequestRefused,
                $locked,
                [
                    'client_id' => $locked->client_id,
                    'request_type' => $locked->type->value,
                    'refusal_reason' => $locked->refusal_reason,
                ],
                causer: $refusedBy,
            );

            return $locked;
        });
    }
}
