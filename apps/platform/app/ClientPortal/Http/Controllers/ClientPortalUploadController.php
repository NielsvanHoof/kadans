<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers;

use App\ClientPortal\Actions\SubmitPortalUploadAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Http\Requests\StorePortalUploadedDocumentRequest;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Actions\DocumentRequests\DeleteUploadedDocumentAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\UploadedDocument;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class ClientPortalUploadController extends Controller
{
    /**
     * Accept a client upload for one document request on the grant's dossier.
     */
    public function store(
        string $tenant,
        StorePortalUploadedDocumentRequest $request,
        int $documentRequest,
        SubmitPortalUploadAction $submitPortalUpload,
    ): RedirectResponse {
        $grant = $this->grantFromRequest($request);

        $documentRequestModel = DocumentRequest::query()->findOrFail($documentRequest);

        abort_unless($documentRequestModel->dossier_id === $grant->dossier_id, 404);

        /** @var list<UploadedFile> $files */
        $files = $request->file('documents', []);

        if ($files === []) {
            return back()->withErrors(['documents' => __('A document file is required.')]);
        }

        foreach ($files as $file) {
            $submitPortalUpload->handle(
                $documentRequestModel,
                $grant,
                $file,
            );
        }

        Toast::success(__('Document uploaded. Thank you.'));

        return to_route('portal.show');
    }

    /**
     * Remove one file from a multi-file document request, before it's reviewed.
     */
    public function destroy(
        string $tenant,
        Request $request,
        int $documentRequest,
        int $uploadedDocument,
        DeleteUploadedDocumentAction $deleteUploadedDocument,
    ): RedirectResponse {
        $grant = $this->grantFromRequest($request);

        $documentRequestModel = DocumentRequest::query()->findOrFail($documentRequest);
        abort_unless($documentRequestModel->dossier_id === $grant->dossier_id, 404);

        $uploadedDocumentModel = UploadedDocument::query()->findOrFail($uploadedDocument);
        abort_unless($uploadedDocumentModel->document_request_id === $documentRequestModel->id, 404);

        // Concealed as 404 rather than a validation error: a request that
        // doesn't accept multiple files, or one already under review, offers
        // no delete button in the UI, so reaching here means a stale page.
        abort_unless(
            ($documentRequestModel->configuration['allow_multiple'] ?? false) === true
                && $documentRequestModel->status === DocumentRequestStatus::Submitted,
            404,
        );

        $deleteUploadedDocument->handle($uploadedDocumentModel);

        Toast::success(__('Document removed.'));

        return to_route('portal.show');
    }

    private function grantFromRequest(Request $request): ClientAccessGrant
    {
        $grant = $request->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);

        abort_unless($grant instanceof ClientAccessGrant, 404);

        return $grant;
    }
}
