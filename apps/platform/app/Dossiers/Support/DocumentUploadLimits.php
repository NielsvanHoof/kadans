<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\UploadedDocument;

/**
 * Shared document upload ceilings for staff and portal FormRequests.
 *
 * Keep in sync with docker/production/php.ini and the frontend dropzone defaults:
 * - per-file max matches upload_max_filesize
 * - post_max_size must cover max files × per-file max (plus form overhead)
 */
final class DocumentUploadLimits
{
    public const int MAX_FILES_WHEN_MULTIPLE = 10;

    /** Laravel File::max() / validation size string. */
    public const string MAX_FILE_SIZE = '20mb';

    /** Human label for PostTooLargeException messaging (10 × 20 MB). */
    public const string MAX_POST_SIZE_LABEL = '200 MB';

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
