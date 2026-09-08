<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\UploadedDocument;

/**
 * Shared document upload ceilings for staff and portal uploads.
 *
 * These used to have to stay at or below the per-environment php.ini
 * `upload_max_filesize` / `post_max_size`, because the files arrived as a
 * multipart body through PHP. They no longer do: the browser PUTs each file
 * straight to S3 with a presigned URL (see DocumentPresigner), so PHP never
 * sees the bytes and no ini directive constrains them.
 *
 * That removes the silent-failure mode behind TASK-42 — a file over
 * `upload_max_filesize` used to be dropped with UPLOAD_ERR_INI_SIZE while
 * `post_max_size` still admitted the body, so there was no 413 and the upload
 * simply looked like a no-op. It also means these constants are now the *only*
 * ceiling: ClaimUploadTicketAction checks them against the object S3 actually
 * stored, and nothing else does.
 *
 * `tests/Feature/Platform/UploadLimitsTest.php` pins them to the frontend
 * dropzone defaults.
 */
final class DocumentUploadLimits
{
    public const int MAX_FILES_WHEN_MULTIPLE = 10;

    /** Per-file ceiling, checked against the stored object's real size. */
    public const int MAX_FILE_BYTES = 20 * 1024 * 1024;

    /** Human label for the validation message. */
    public const string MAX_FILE_SIZE_LABEL = '20 MB';

    /**
     * Cumulative portal upload ceiling per dossier (all document requests).
     * Caps storage + OCR cost when a grant is re-used across many POSTs.
     */
    public const int MAX_PORTAL_BYTES_PER_DOSSIER = 500 * 1024 * 1024;

    public const string MAX_PORTAL_BYTES_PER_DOSSIER_LABEL = '500 MB';

    /**
     * Bytes already stored against a dossier, across all of its document requests.
     *
     * One definition, two enforcement points: the portal FormRequest sums this
     * before the write to produce a friendly validation error, and
     * SubmitPortalUploadAction sums it again inside the transaction that holds
     * the dossier lock, which is the one that actually holds under concurrency.
     *
     * Build on getQuery(), terminate on toBase() so TenantScope is applied to
     * both the outer sum and the document-request subquery.
     */
    public static function portalBytesUsedForDossier(int $dossierId): int
    {
        $dossierDocumentRequests = DocumentRequest::query();
        $dossierDocumentRequests->getQuery()
            ->where('dossier_id', $dossierId)
            ->select('id');

        $dossierUploads = UploadedDocument::query();
        $dossierUploads->getQuery()
            ->whereIn('document_request_id', $dossierDocumentRequests->toBase());

        return (int) $dossierUploads->toBase()->sum('size_bytes');
    }

    /**
     * The one message both enforcement points show when the cap is reached.
     */
    public static function portalCapMessage(): string
    {
        return __('This dossier has reached its portal upload limit of :max. Remove a file or contact your advisor.', [
            'max' => self::MAX_PORTAL_BYTES_PER_DOSSIER_LABEL,
        ]);
    }
}
