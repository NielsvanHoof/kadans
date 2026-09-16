<?php

declare(strict_types=1);

namespace App\ClientPortal\Queries\Workspace;

use App\Accounts\Models\User;
use App\ClientPortal\Data\Session\PortalSessionData;
use App\ClientPortal\Data\Workspace\ClientPortalPageData;
use App\ClientPortal\Data\Workspace\PortalClientData;
use App\ClientPortal\Data\Workspace\PortalDocumentRequestData;
use App\ClientPortal\Data\Workspace\PortalDossierData;
use App\ClientPortal\Data\Workspace\PortalDossierSummaryData;
use App\ClientPortal\Data\Workspace\PortalFirmData;
use App\ClientPortal\Data\Workspace\PortalNextIncompleteData;
use App\ClientPortal\Data\Workspace\PortalProgressData;
use App\ClientPortal\Data\Workspace\PortalUploadedDocumentData;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Support\Session\PortalSessionWindow;
use App\Clients\Models\Client;
use App\Dossiers\Actions\DocumentRequests\DetermineDocumentRequestVisibilityAction;
use App\Dossiers\Actions\DocumentRequests\ResolveApplicableDocumentRequestsAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Transitions\DocumentRequests\DocumentRequestTransitions;
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

    /**
     * $dossier arrives already resolved by ResolveGrantedDossierQuery. This
     * query deliberately cannot pick one itself: which dossiers a link opens is
     * an authorization question with exactly one owner, and a page payload is
     * not it.
     *
     * @param  list<PortalDossierSummaryData>  $otherDossiers
     */
    public function handle(
        ClientAccessGrant $grant,
        Dossier $dossier,
        PortalSessionWindow $sessionWindow,
        array $otherDossiers = [],
    ): ClientPortalPageData {
        $dossier->loadMissing([
            'client:id,name,email',
            'tenant:id,name',
            'responsibleUser:id,name,email',
            'documentRequests' => fn ($query) => $query
                ->oldest('sort_order')
                ->oldest('id'),
        ]);

        // Separate, and dot-notated, on purpose. The landing pass may already
        // have loaded documentRequests to count them — at which point a nested
        // `with()` above is silently skipped, because loadMissing() will not
        // re-load a relation that is already there. Asking for the child path
        // fills it in on whatever is loaded, and still costs one query when the
        // parent came in cold.
        $dossier->loadMissing(
            'documentRequests.uploadedDocuments:id,document_request_id,original_filename,size_bytes,uploaded_at',
        );

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
                isCarriedForward: $documentRequest->carried_forward_at !== null,
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
                id: $dossier->id,
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
            otherDossiers: $otherDossiers,
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
