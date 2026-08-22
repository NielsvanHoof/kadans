<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

/**
 * Per-file ceiling for staff audio recording uploads.
 *
 * Must stay at or below `upload_max_filesize` in every environment
 * (`docker/8.5/Caddyfile`, `docker/staging/php.ini`, `docker/production/php.ini`).
 * Above it, PHP rejects the part with UPLOAD_ERR_INI_SIZE while `post_max_size`
 * still lets the body through, so there is no 413 — the FormRequest just fails
 * on `uploaded` and the upload looks like a silent no-op (TASK-42).
 * `tests/Feature/Platform/UploadLimitsTest.php` fails if the two drift apart.
 */
final class AudioUploadLimits
{
    /** Laravel File::max() / validation size string. */
    public const string MAX_FILE_SIZE = '25mb';

    /** Human label for the validation message. */
    public const string MAX_FILE_SIZE_LABEL = '25 MB';
}
