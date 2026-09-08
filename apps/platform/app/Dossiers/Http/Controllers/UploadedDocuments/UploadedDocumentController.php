<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Actions\DocumentRequests\DeleteUploadedDocumentAction;
use App\Dossiers\Actions\DocumentRequests\UploadStaffDocumentAction;
use App\Dossiers\Actions\GuardUploadedDocumentDeliveryAction;
use App\Dossiers\Actions\Uploads\ClaimUploadTicketAction;
use App\Dossiers\Data\StaffUploadedDocumentData;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Http\Requests\DocumentRequests\StoreUploadedDocumentRequest;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Queries\FetchUploadedDocumentShowQuery;
use App\Dossiers\Support\StagedUpload;
use App\Dossiers\Support\UploadedDocumentResponse;
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
