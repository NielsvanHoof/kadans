<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Actions\DocumentRequests\ResolveDocumentTemporaryUrlAction;
use App\Dossiers\Actions\DocumentRequests\UploadStaffDocumentAction;
use App\Dossiers\Data\StaffUploadedDocumentData;
use App\Dossiers\Http\Requests\DocumentRequests\StoreUploadedDocumentRequest;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Queries\FetchUploadedDocumentShowQuery;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff upload + OCR extraction page + document download.
 */
final class UploadedDocumentController extends Controller
{
    /**
     * Response hardening for bytes a client uploaded.
     *
     * On S3 these files are served from the storage host via a signed URL, but the
     * local/CI disk streams them straight off this origin, and `mime_type` comes
     * from the upload — so it must never be able to drive script execution here.
     *
     * The policy blocks scripts rather than using `default-src 'none'` or a bare
     * `sandbox`: both of those are known to interfere with the browser's built-in
     * PDF viewer, and PDF preview is the main reason this endpoint exists.
     * `script-src 'none'` still stops JavaScript in a smuggled HTML/SVG file *and*
     * embedded JavaScript inside a PDF, which is the actual threat.
     */
    private const array UNTRUSTED_FILE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        'Referrer-Policy' => 'no-referrer',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
    ];

    public function store(
        Tenant $tenant,
        StoreUploadedDocumentRequest $request,
        Dossier $dossier,
        DocumentRequest $documentRequest,
        UploadStaffDocumentAction $uploadStaffDocument,
    ): RedirectResponse {
        // Parent ownership is enforced by scoped route bindings
        // (dossier → documentRequest). Tenant scope is already applied.
        $this->authorize('view', $dossier);

        $file = $request->file('document');

        if ($file === null) {
            return back()->withErrors(['document' => __('A document file is required.')]);
        }

        $uploadStaffDocument->handle($documentRequest, $file);

        Toast::success(__('Document uploaded.'));

        // Staff uploads land on the focused review page.
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
    ): Response|JsonResponse {
        $this->authorize('view', $uploadedDocument);

        $props = $fetchUploadedDocumentShow->handle($uploadedDocument);

        if ($request->expectsJson()) {
            return response()->json([
                'uploaded_document' => $props['uploaded_document'],
            ]);
        }

        return Inertia::render('uploaded-documents/show', $props);
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
        ResolveDocumentTemporaryUrlAction $resolveDocumentTemporaryUrl,
    ): RedirectResponse|StreamedResponse {
        $this->authorize('download', $uploadedDocument);

        $logAuditActivity->handle(
            AuditEvent::DocumentDownloaded,
            $uploadedDocument,
            ['original_filename' => $uploadedDocument->original_filename],
        );

        return $this->serveDocument(
            $uploadedDocument,
            $resolveDocumentTemporaryUrl,
            disposition: 'attachment',
        );
    }

    /**
     * Inline file response for in-page PDF/image previews (no download audit).
     */
    public function preview(
        Tenant $tenant,
        UploadedDocument $uploadedDocument,
        ResolveDocumentTemporaryUrlAction $resolveDocumentTemporaryUrl,
    ): RedirectResponse|StreamedResponse {
        $this->authorize('download', $uploadedDocument);

        return $this->serveDocument(
            $uploadedDocument,
            $resolveDocumentTemporaryUrl,
            disposition: 'inline',
        );
    }

    /**
     * Stream or redirect to the stored file with the given Content-Disposition.
     */
    private function serveDocument(
        UploadedDocument $uploadedDocument,
        ResolveDocumentTemporaryUrlAction $resolveDocumentTemporaryUrl,
        string $disposition,
    ): RedirectResponse|StreamedResponse {
        if ($resolveDocumentTemporaryUrl->supportsTemporaryUrl($uploadedDocument)) {
            $url = $resolveDocumentTemporaryUrl->handle(
                $uploadedDocument,
                now()->addMinutes(5),
                $disposition,
            );

            return redirect()->away($url);
        }

        // Local/CI disks keep streaming through Laravel (Storage::fake friendly).
        if ($disposition === 'inline') {
            return Storage::disk($uploadedDocument->disk)->response(
                $uploadedDocument->path,
                $uploadedDocument->original_filename,
                ['Content-Type' => $uploadedDocument->mime_type] + self::UNTRUSTED_FILE_HEADERS,
            );
        }

        return Storage::disk($uploadedDocument->disk)->download(
            $uploadedDocument->path,
            $uploadedDocument->original_filename,
            self::UNTRUSTED_FILE_HEADERS,
        );
    }
}
