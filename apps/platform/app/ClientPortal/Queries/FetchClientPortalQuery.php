<?php

declare(strict_types=1);

namespace App\ClientPortal\Queries;

use App\Accounts\Models\User;
use App\ClientPortal\Data\ClientPortalPageData;
use App\ClientPortal\Data\PortalClientData;
use App\ClientPortal\Data\PortalDocumentRequestData;
use App\ClientPortal\Data\PortalDossierData;
use App\ClientPortal\Data\PortalFirmData;
use App\ClientPortal\Data\PortalNextIncompleteData;
use App\ClientPortal\Data\PortalProgressData;
use App\ClientPortal\Data\PortalSessionData;
use App\ClientPortal\Data\PortalUploadedDocumentData;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Support\PortalSessionWindow;
use App\Clients\Models\Client;
use App\Dossiers\Actions\DocumentRequests\DetermineDocumentRequestVisibilityAction;
use App\Dossiers\Actions\DocumentRequests\ResolveApplicableDocumentRequestsAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Transitions\DocumentRequestTransitions;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use function in_array;

final readonly class FetchClientPortalQuery
{
    public function __construct(
        private DetermineDocumentRequestVisibilityAction $determineVisibility,
        private ResolveApplicableDocumentRequestsAction $resolveApplicableDocumentRequests,
        private DocumentRequestTransitions $documentRequestTransitions,
    ) {}

    public function handle(
        ClientAccessGrant $grant,
        PortalSessionWindow $sessionWindow,
    ): ClientPortalPageData {
        $dossier = Dossier::query()
            ->with([
                'client:id,name,email',
                'tenant:id,name',
                'responsibleUser:id,name,email',
                'documentRequests' => fn ($query) => $query
                    ->oldest('sort_order')
                    ->oldest('id')
                    ->with('uploadedDocuments:id,document_request_id,original_filename,size_bytes,uploaded_at'),
            ])
            ->findOrFail($grant->dossier_id);

        $tenant = $this->resolveTenant($dossier);
        $client = $this->resolveClient($dossier);
        // Portal only lists currently visible fields; progress uses applicable ones.
        $visibleDocumentRequests = $dossier->documentRequests->filter(
            fn (DocumentRequest $documentRequest): bool => $this->determineVisibility->handle(
                $documentRequest,
                $dossier->documentRequests,
            ),
        );
        $applicableDocumentRequests = $this->resolveApplicableDocumentRequests->handle(
            $dossier->documentRequests,
        );
        $totalItemCount = $applicableDocumentRequests->count();
        $remainingItems = $applicableDocumentRequests->filter(
            fn (DocumentRequest $documentRequest): bool => in_array(
                $documentRequest->status->value,
                ['pending', 'rejected'],
                true,
            ),
        );
        $approvedItemCount = $applicableDocumentRequests
            ->where('status', DocumentRequestStatus::Accepted)
            ->count();
        $nextIncompleteItem = $remainingItems->first();

        /** @var list<PortalDocumentRequestData> $documentRequests */
        $documentRequests = [];

        foreach ($visibleDocumentRequests as $documentRequest) {
            $documentRequests[] = new PortalDocumentRequestData(
                id: $documentRequest->id,
                type: $documentRequest->type,
                fieldKey: $documentRequest->field_key,
                title: $documentRequest->title,
                instructions: $documentRequest->instructions,
                status: $documentRequest->status,
                answerText: $documentRequest->answer_text,
                answerBoolean: $documentRequest->answer_boolean,
                answerOptionIds: $documentRequest->answer_option_ids,
                configuration: $documentRequest->configuration,
                rejectionReason: $documentRequest->rejection_reason,
                canRespond: $this->documentRequestTransitions->canSubmit(
                    $documentRequest,
                    SubmissionContext::Portal,
                    $dossier,
                ),
                uploadedDocuments: array_values($documentRequest->uploadedDocuments
                    ->map(fn (UploadedDocument $uploadedDocument): PortalUploadedDocumentData => new PortalUploadedDocumentData(
                        id: $uploadedDocument->id,
                        originalFilename: $uploadedDocument->original_filename,
                        sizeBytes: $uploadedDocument->size_bytes,
                        uploadedAt: $uploadedDocument->uploaded_at->toIso8601String(),
                    ))
                    ->all()),
            );
        }

        $contact = $this->resolveContact($dossier, $grant);

        return new ClientPortalPageData(
            firm: new PortalFirmData(
                name: $tenant->name,
                contactName: $contact->name,
                contactEmail: $contact->email,
            ),
            dossier: new PortalDossierData(
                title: $dossier->title,
                reference: $dossier->reference,
                status: $dossier->status,
                dueDate: $dossier->due_date?->toDateString(),
                client: new PortalClientData(name: $client->name),
                expiresAt: $grant->expires_at->toIso8601String(),
                progress: new PortalProgressData(
                    total: $totalItemCount,
                    completed: $totalItemCount - $remainingItems->count(),
                    approved: $approvedItemCount,
                    remaining: $remainingItems->count(),
                    nextIncomplete: $nextIncompleteItem instanceof DocumentRequest
                        ? new PortalNextIncompleteData(
                            id: $nextIncompleteItem->id,
                            title: $nextIncompleteItem->title,
                        )
                        : null,
                ),
                documentRequests: $documentRequests,
            ),
            session: PortalSessionData::fromWindow($sessionWindow),
        );
    }

    /**
     * Who the client should contact. The dossier's responsible staff member
     * first, then whoever issued the access link — both already correspond with
     * this client, so neither discloses anything new.
     *
     * Always resolves: `created_by` cascades on delete, so a live grant always
     * has a live creator to fall back to.
     */
    private function resolveContact(Dossier $dossier, ClientAccessGrant $grant): User
    {
        $responsibleUser = $dossier->responsibleUser;

        if ($responsibleUser instanceof User) {
            return $responsibleUser;
        }

        $grant->loadMissing('createdBy:id,name,email');

        return $grant->createdBy;
    }

    private function resolveTenant(Dossier $dossier): Tenant
    {
        $tenant = $dossier->tenant;

        if (! $tenant instanceof Tenant) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$dossier->tenant_id]);
        }

        return $tenant;
    }

    private function resolveClient(Dossier $dossier): Client
    {
        $client = $dossier->client;

        if (! $client instanceof Client) {
            throw (new ModelNotFoundException)->setModel(Client::class, [$dossier->client_id]);
        }

        return $client;
    }
}
