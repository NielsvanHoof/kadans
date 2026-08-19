<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Actions\DocumentRequests\DeleteUploadedDocumentAction;
use App\Dossiers\Actions\DocumentRequests\ResolveDocumentTemporaryUrlAction;
use App\Dossiers\Actions\DocumentRequests\UploadStaffDocumentAction;
use App\Dossiers\Actions\ResolveMalwareScanStatusAction;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function sprintf;

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

        /** @var list<UploadedFile> $files */
        $files = $request->file('documents', []);

        if ($files === []) {
            return back()->withErrors(['documents' => __('At least one file is required.')]);
        }

        foreach ($files as $file) {
            $uploadStaffDocument->handle($documentRequest, $file);
        }

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
        ResolveMalwareScanStatusAction $resolveMalwareScanStatus,
    ): RedirectResponse|StreamedResponse {
        $this->authorize('download', $uploadedDocument);

        $this->assertScannedClean($uploadedDocument, $logAuditActivity, $resolveMalwareScanStatus);

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
     * Inline file response for in-page PDF/image previews.
     */
    public function preview(
        Tenant $tenant,
        UploadedDocument $uploadedDocument,
        LogAuditActivityAction $logAuditActivity,
        ResolveDocumentTemporaryUrlAction $resolveDocumentTemporaryUrl,
        ResolveMalwareScanStatusAction $resolveMalwareScanStatus,
    ): RedirectResponse|StreamedResponse {
        $this->authorize('download', $uploadedDocument);

        $this->assertScannedClean($uploadedDocument, $logAuditActivity, $resolveMalwareScanStatus);

        $logAuditActivity->handle(
            AuditEvent::DocumentViewed,
            $uploadedDocument,
            [
                'surface' => 'preview',
                'original_filename' => $uploadedDocument->original_filename,
            ],
        );

        return $this->serveDocument(
            $uploadedDocument,
            $resolveDocumentTemporaryUrl,
            disposition: 'inline',
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
     * Refuse anything GuardDuty has not established as clean.
     *
     * Placed ahead of the audit call in both callers rather than inside
     * serveDocument(), so a blocked request records the block instead of
     * recording a download that never happened.
     *
     * Fails closed on an unscanned file too. Scans are asynchronous, so a
     * freshly uploaded document has no verdict yet — and at the point of
     * delivery "not yet scanned" and "infected" are the same fact. The refusal
     * is audited because a staff member being told no is exactly the sort of
     * thing an operator needs to see without being asked.
     */
    private function assertScannedClean(
        UploadedDocument $uploadedDocument,
        LogAuditActivityAction $logAuditActivity,
        ResolveMalwareScanStatusAction $resolveMalwareScanStatus,
    ): void {
        $verdict = $resolveMalwareScanStatus->handle($uploadedDocument->disk, $uploadedDocument->path);

        if ($verdict->permitsDelivery) {
            return;
        }

        $logAuditActivity->handle(
            AuditEvent::FileBlockedByMalwareScan,
            $uploadedDocument,
            [
                'original_filename' => $uploadedDocument->original_filename,
                // Null reads as "no verdict yet", which is the common case and a
                // materially different situation from a detection.
                'scan_status' => $verdict->status?->value,
            ],
        );

        abort(403, __('This file has not been cleared by the malware scanner.'));
    }

    /**
     * Stream or redirect to the stored file with the given Content-Disposition.
     */
    private function serveDocument(
        UploadedDocument $uploadedDocument,
        ResolveDocumentTemporaryUrlAction $resolveDocumentTemporaryUrl,
        string $disposition,
    ): RedirectResponse|StreamedResponse {
        $driver = $resolveDocumentTemporaryUrl->diskDriver($uploadedDocument);

        if ($driver === null) {
            // The row names a disk that is no longer configured, so the bytes
            // are unreachable. Fail closed as a miss rather than a 500: the
            // caller cannot act on it either way, but operators still need to
            // know a row outlived its disk, so it is reported.
            report(new RuntimeException(sprintf(
                'Uploaded document %d is stored on disk "%s", which is not configured.',
                $uploadedDocument->id,
                $uploadedDocument->disk,
            )));

            abort(404);
        }

        if ($driver === 's3') {
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
