<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

/**
 * Per-file ceiling for staff audio recording uploads.
 *
 * Recordings now reach S3 through a presigned PUT rather than a multipart
 * request body, so this is no longer shadowed by php.ini's
 * `upload_max_filesize` — and no longer subject to the silent
 * UPLOAD_ERR_INI_SIZE drop that TASK-42 chased down. ClaimUploadTicketAction
 * checks it against the object S3 actually stored, which is the only place the
 * real size is known.
 *
 * `tests/Feature/Platform/UploadLimitsTest.php` pins it to the frontend
 * dropzone default.
 */
final class AudioUploadLimits
{
    /** Per-file ceiling, checked against the stored object's real size. */
    public const int MAX_FILE_BYTES = 25 * 1024 * 1024;

    /** Human label for the validation message. */
    public const string MAX_FILE_SIZE_LABEL = '25 MB';
}
