<?php

declare(strict_types=1);

namespace App\Platform\Actions\DataSubject;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Models\DataSubjectRequest;
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
    ) {}

    public function handle(
        DataSubjectRequest $request,
        User $refusedBy,
        string $reason,
    ): DataSubjectRequest {
        throw_unless($request->status->isOpen(), ValidationException::withMessages([
            'status' => __('Only an open request can be refused.'),
        ]));

        throw_if(mb_trim($reason) === '', ValidationException::withMessages([
            'refusal_reason' => __('A refusal must record why.'),
        ]));

        return DB::transaction(function () use ($request, $refusedBy, $reason): DataSubjectRequest {
            $request->forceFill([
                'status' => DataSubjectRequestStatus::Refused,
                'refused_at' => now(),
                'refused_by' => $refusedBy->id,
                'refusal_reason' => mb_substr(mb_trim($reason), 0, 500),
            ])->save();

            $this->logAuditActivity->handle(
                AuditEvent::DataSubjectRequestRefused,
                $request,
                [
                    'client_id' => $request->client_id,
                    'request_type' => $request->type->value,
                    'refusal_reason' => $request->refusal_reason,
                ],
                causer: $refusedBy,
            );

            return $request;
        });
    }
}
