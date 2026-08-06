<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

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
}
