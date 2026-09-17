<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers\UploadedDocuments;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Actions\UploadedDocuments\DeleteUploadedDocumentAction;
use App\Dossiers\Actions\UploadedDocuments\GuardUploadedDocumentDeliveryAction;
use App\Dossiers\Actions\UploadedDocuments\UploadStaffDocumentAction;
use App\Dossiers\Actions\Uploads\ClaimUploadTicketAction;
use App\Dossiers\Data\UploadedDocuments\StaffUploadedDocumentData;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Http\Requests\UploadedDocuments\StoreUploadedDocumentRequest;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Queries\UploadedDocuments\FetchUploadedDocumentShowQuery;
use App\Dossiers\Support\StoredFileResponse;
use App\Dossiers\Support\UploadedDocuments\UploadedDocumentResponse;
use App\Dossiers\Support\Uploads\StagedUpload;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Staff upload + OCR extraction page + document download.
 */
final class UploadedDocumentController extends Controller
{
    public function store(
        Tenant $tenant,
        StoreUploadedDocumentRequest $request,
        Dossier $dossier,
        DocumentRequest $documentRequest,
        UploadStaffDocumentAction $uploadStaffDocument,
        ClaimUploadTicketAction $claimUploadTicket,
    ): RedirectResponse {
        // Parent ownership is enforced by scoped route bindings
        // (dossier → documentRequest). Tenant scope is already applied.
        $this->authorize('view', $dossier);

        try {
            $uploads = array_map(
                fn (string $ticket): StagedUpload => $claimUploadTicket->handle(
                    $ticket,
                    UploadPurpose::Document,
                    $tenant->id,
                    'documents',
                ),
                $request->array('documents'),
            );

            foreach ($uploads as $upload) {
                $uploadStaffDocument->handle($documentRequest, $upload);
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['documents' => __('Something went wrong while uploading the document. Please try again.')]);
        }

        Toast::success(__('Document uploaded.'));

        return to_route(
            'workspaces.dossiers.review',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }

    /**
     * Dedicated OCR extraction page — key/values and tables off the dossier list.
     * JSON powers the extraction-page island (full OCR body).
     */
    public function show(
        Tenant $tenant,
        Request $request,
        UploadedDocument $uploadedDocument,
        FetchUploadedDocumentShowQuery $fetchUploadedDocumentShow,
        LogAuditActivityAction $logAuditActivity,
    ): Response|JsonResponse {
        $this->authorize('view', $uploadedDocument);

        $props = $fetchUploadedDocumentShow->handle($uploadedDocument);

        if ($request->expectsJson()) {
            return response()->json([
                'uploaded_document' => $props['uploaded_document'],
            ]);
        }

        $logAuditActivity->handle(
            AuditEvent::DocumentViewed,
            $uploadedDocument,
            [
                'surface' => 'extraction',
                'original_filename' => $uploadedDocument->original_filename,
            ],
        );

        return Inertia::render('workspaces/uploaded-documents/show', $props);
    }

    /**
     * Narrow processing slice for review/show islands (no extraction, no password gate).
     */
    public function live(
        Tenant $tenant,
        UploadedDocument $uploadedDocument,
    ): JsonResponse {
        $this->authorize('view', $uploadedDocument);

        return response()->json([
            'uploaded_document' => StaffUploadedDocumentData::fromModel($uploadedDocument)->toArray(),
        ]);
    }

    /**
     * Authorize, audit, then either redirect to a signed S3 URL or stream locally.
     */
    public function download(
        Tenant $tenant,
        UploadedDocument $uploadedDocument,
        LogAuditActivityAction $logAuditActivity,
        UploadedDocumentResponse $uploadedDocumentResponse,
        GuardUploadedDocumentDeliveryAction $guardUploadedDocumentDelivery,
    ): HttpResponse {
        $this->authorize('download', $uploadedDocument);

        $this->assertScannedClean($uploadedDocument, $guardUploadedDocumentDelivery);

        $logAuditActivity->handle(
            AuditEvent::DocumentDownloaded,
            $uploadedDocument,
            ['original_filename' => $uploadedDocument->original_filename],
        );

        return $uploadedDocumentResponse->serve($uploadedDocument, disposition: 'attachment');
    }

    /**
     * Inline file response for in-page PDF/image previews.
     */
    public function preview(
        Tenant $tenant,
        UploadedDocument $uploadedDocument,
        LogAuditActivityAction $logAuditActivity,
        UploadedDocumentResponse $uploadedDocumentResponse,
        GuardUploadedDocumentDeliveryAction $guardUploadedDocumentDelivery,
    ): HttpResponse {
        $this->authorize('download', $uploadedDocument);

        $this->assertScannedClean($uploadedDocument, $guardUploadedDocumentDelivery);

        $logAuditActivity->handle(
            AuditEvent::DocumentViewed,
            $uploadedDocument,
            [
                'surface' => 'preview',
                'original_filename' => $uploadedDocument->original_filename,
            ],
        );

        return $uploadedDocumentResponse->serve($uploadedDocument, disposition: 'inline');
    }

    /**
     * The rendered first-page preview, for document lists and cards.
     *
     * Two things here are deliberate and neither is an oversight.
     *
     * **It does not go through GuardUploadedDocumentDeliveryAction.** A
     * thumbnail is not the client's file: it is output this application's own
     * Lambda rendered from an object GuardDuty had already declared clean, and
     * it carries no scan tag of its own. Putting it through the gate would fail
     * closed on every thumbnail forever. The ordering is what makes that safe —
     * the render is triggered by the clean verdict, so bytes that were never
     * cleared are never rendered in the first place.
     *
     * **It is not behind RequirePassword, unlike preview and download.** It
     * cannot be: an `<img>` in a list would follow the redirect to the password
     * page and render as a broken image, which is the entire feature. The
     * accepted cost is real and worth stating — a 400x560 render of page one is
     * meaningful content, so for a member whose password confirmation has
     * lapsed this reveals a little more than the file name they could already
     * see. The `download` ability is still required, so it is never a way into
     * a dossier the member has no access to.
     *
     * Not audited. It fires once per thumbnail per list render, and burying
     * DocumentViewed under that volume would cost more than it records.
     */
    public function thumbnail(
        Tenant $tenant,
        UploadedDocument $uploadedDocument,
        StoredFileResponse $storedFileResponse,
    ): HttpResponse {
        $this->authorize('download', $uploadedDocument);

        $thumbnailPath = $uploadedDocument->thumbnail_path;

        // No thumbnail is the ordinary case, not an error: the scan may still be
        // in flight, the verdict may not have been clean, or the format may have
        // no renderer. The interface falls back to a file-type icon on a 404.
        abort_if($thumbnailPath === null || $thumbnailPath === '', 404);

        return $storedFileResponse->serve(
            disk: $uploadedDocument->disk,
            path: $thumbnailPath,
            // Never the client's original filename: this is our JPEG, and the
            // upload's name has no business in a content-disposition header.
            filename: 'thumbnail.jpg',
            mimeType: 'image/jpeg',
            disposition: 'inline',
            headers: UploadedDocumentResponse::UNTRUSTED_FILE_HEADERS,
        );
    }

    /**
     * Remove one file from a multi-file document request.
     */
    public function destroy(
        Tenant $tenant,
        UploadedDocument $uploadedDocument,
        DeleteUploadedDocumentAction $deleteUploadedDocument,
    ): RedirectResponse {
        $this->authorize('delete', $uploadedDocument);

        $deleteUploadedDocument->handle($uploadedDocument);

        Toast::success(__('Document removed.'));

        return back();
    }

    /**
     * Placed ahead of the audit call in both callers rather than inside the
     * response builder, so a blocked request records the block instead of
     * recording a download that never happened.
     *
     * The verdict, the fail-closed-on-unscanned rule, and the audit row live in
     * GuardUploadedDocumentDeliveryAction, which the client portal shares. Only
     * the 403 is staff-specific — portal denials are 404.
     */
    private function assertScannedClean(
        UploadedDocument $uploadedDocument,
        GuardUploadedDocumentDeliveryAction $guardUploadedDocumentDelivery,
    ): void {
        abort_unless(
            $guardUploadedDocumentDelivery->handle($uploadedDocument),
            403,
            __('This file hasn\'t passed our security check yet. Try again in a moment.'),
        );
    }
}
