<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Submissions;

use App\ClientPortal\Actions\Submissions\SubmitPortalUploadAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Http\Requests\Submissions\StorePortalUploadedDocumentRequest;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\Access\ResolveGrantedDossierQuery;
use App\Dossiers\Actions\UploadedDocuments\DeleteUploadedDocumentAction;
use App\Dossiers\Actions\UploadedDocuments\GuardUploadedDocumentDeliveryAction;
use App\Dossiers\Actions\Uploads\ClaimUploadTicketAction;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\UploadedDocuments\UploadedDocumentResponse;
use App\Dossiers\Support\Uploads\StagedUpload;
use App\Dossiers\Transitions\DocumentRequests\DocumentRequestTransitions;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ClientPortalUploadController extends Controller
{
    public function __construct(private readonly ResolveGrantedDossierQuery $resolveGrantedDossier) {}

    /**
     * Accept a client upload for one document request on the grant's dossier.
     */
    public function store(
        StorePortalUploadedDocumentRequest $request,
        int $documentRequest,
        SubmitPortalUploadAction $submitPortalUpload,
        ClaimUploadTicketAction $claimUploadTicket,
    ): RedirectResponse {
        $grant = $this->grantFromRequest($request);

        $documentRequestModel = DocumentRequest::query()->findOrFail($documentRequest);

        $dossier = $this->resolveGrantedDossier->handle($grant, $documentRequestModel->dossier_id);

        /*
         * The ticket's tenant must be the dossier's tenant, so a ticket minted
         * against one workspace cannot be redeemed through another workspace's
         * portal grant. Claim the whole batch before storing any of it.
         */
        $uploads = array_map(
            fn (string $ticket): StagedUpload => $claimUploadTicket->handle(
                $ticket,
                UploadPurpose::Document,
                $documentRequestModel->tenant_id,
                'documents',
            ),
            $request->array('documents'),
        );

        foreach ($uploads as $upload) {
            $submitPortalUpload->handle(
                $documentRequestModel,
                $grant,
                $upload,
            );
        }

        Toast::success(__('Document uploaded. Thank you.'));

        return to_route('portal.dossiers.show', ['dossier' => $dossier->getKey()]);
    }

    /**
     * Remove one file from a multi-file document request, before it's reviewed.
     */
    public function destroy(
        Request $request,
        int $documentRequest,
        int $uploadedDocument,
        DeleteUploadedDocumentAction $deleteUploadedDocument,
        DocumentRequestTransitions $documentRequestTransitions,
    ): RedirectResponse {
        [$dossier, $documentRequestModel, $uploadedDocumentModel] = $this->uploadedDocumentOnGrantedDossier(
            $request,
            $documentRequest,
            $uploadedDocument,
        );

        // Concealed as 404 rather than a validation error: a request that
        // doesn't accept multiple files, or one already under review, offers
        // no delete button in the UI, so reaching here means a stale page.
        abort_unless(
            $documentRequestTransitions->canDeleteUpload(
                $documentRequestModel,
                SubmissionContext::Portal,
            ),
            404,
        );

        $deleteUploadedDocument->handle($uploadedDocumentModel);

        Toast::success(__('Document removed.'));

        return to_route('portal.dossiers.show', ['dossier' => $dossier->getKey()]);
    }

    /**
     * Give the client back a file they sent us.
     *
     * They could previously see a filename and nothing else, so a client who
     * uploaded the wrong page of a bank statement had no way to check — and no
     * way to replace it on a single-file item. This is also their own personal
     * data, which is an access right, not a courtesy.
     *
     * No RequirePassword here, unlike every staff route that streams bytes: a
     * portal client has no account to re-authenticate with. The gate is the
     * hashed grant, the short session, and the same malware verdict staff face.
     */
    public function download(
        Request $request,
        int $documentRequest,
        int $uploadedDocument,
        UploadedDocumentResponse $uploadedDocumentResponse,
        GuardUploadedDocumentDeliveryAction $guardUploadedDocumentDelivery,
    ): HttpResponse {
        [, , $uploadedDocumentModel] = $this->uploadedDocumentOnGrantedDossier(
            $request,
            $documentRequest,
            $uploadedDocument,
        );

        // 404 rather than staff's 403: portal denials never disclose a reason.
        abort_unless($guardUploadedDocumentDelivery->handle($uploadedDocumentModel), 404);

        return $uploadedDocumentResponse->serve($uploadedDocumentModel, disposition: 'attachment');
    }

    /**
     * Resolve one upload, proving it hangs off a document request on the very
     * dossier this grant opens. Everything that does not is a 404 — the portal
     * conceals cross-dossier probes rather than confirming ids exist.
     *
     * Returns the dossier and the parent too: deletion has to ask the
     * transitions whether the item still accepts changes, and both callers
     * redirect back to the dossier the client was working in.
     *
     * @return array{Dossier, DocumentRequest, UploadedDocument}
     */
    private function uploadedDocumentOnGrantedDossier(
        Request $request,
        int $documentRequest,
        int $uploadedDocument,
    ): array {
        $grant = $this->grantFromRequest($request);

        $documentRequestModel = DocumentRequest::query()->findOrFail($documentRequest);
        $dossier = $this->resolveGrantedDossier->handle($grant, $documentRequestModel->dossier_id);

        $uploadedDocumentModel = UploadedDocument::query()->findOrFail($uploadedDocument);
        abort_unless($uploadedDocumentModel->document_request_id === $documentRequestModel->id, 404);

        return [$dossier, $documentRequestModel, $uploadedDocumentModel];
    }

    private function grantFromRequest(Request $request): ClientAccessGrant
    {
        $grant = $request->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);

        abort_unless($grant instanceof ClientAccessGrant, 404);

        return $grant;
    }
}
