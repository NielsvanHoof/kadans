<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Actions\DocumentRequests\ResolveApplicableDocumentRequestsAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierLocks;
use App\Dossiers\Transitions\DossierTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CompleteDossierAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private DossierTransitions $dossierTransitions,
        private ResolveApplicableDocumentRequestsAction $resolveApplicableDocumentRequests,
        private DossierLocks $dossierLocks,
    ) {}

    public function handle(Dossier $dossier, User $completedBy): Dossier
    {
        return DB::transaction(function () use ($dossier, $completedBy): Dossier {
            $lockedDossier = $this->dossierLocks->dossier($dossier);

            // Need configuration + answers so visibility / optional rules can resolve.
            $documentRequests = $this->dossierLocks->documentRequestsFor($lockedDossier);
            $applicableDocumentRequests = $this->resolveApplicableDocumentRequests->handle(
                $documentRequests,
            );
            $requestCount = $applicableDocumentRequests->count();
            $hasUnacceptedRequest = $applicableDocumentRequests->contains(
                fn (DocumentRequest $documentRequest): bool => $documentRequest->status
                    !== DocumentRequestStatus::Accepted,
            );

            if ($requestCount === 0 || $hasUnacceptedRequest) {
                throw ValidationException::withMessages([
                    'dossier' => 'Every questionnaire item must be approved before completing the dossier.',
                ]);
            }

            $this->dossierTransitions->complete($lockedDossier);

            $this->logAuditActivity->handle(
                AuditEvent::DossierCompleted,
                $lockedDossier,
                ['document_request_count' => $requestCount],
                $completedBy,
            );

            return $lockedDossier->fresh() ?? $lockedDossier;
        });
    }
}
