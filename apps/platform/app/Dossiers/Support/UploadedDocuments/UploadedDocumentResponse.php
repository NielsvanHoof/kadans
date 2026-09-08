<?php

declare(strict_types=1);

namespace App\Dossiers\Support\UploadedDocuments;

use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\StoredFileResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the bytes a client uploaded, to staff and to the client themselves.
 *
 * Extracted from UploadedDocumentController when the portal gained its own
 * download route: the hardening below is the whole reason this is not two lines
 * of Storage::download() at each call site, and it must not drift between the
 * staff and portal surfaces.
 */
final readonly class UploadedDocumentResponse
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
    public const array UNTRUSTED_FILE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        'Referrer-Policy' => 'no-referrer',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
    ];

    public function __construct(
        private StoredFileResponse $storedFileResponse,
    ) {}

    /**
     * Redirect to a signed S3 URL, or stream from the local/CI disk.
     *
     * @param  'attachment'|'inline'  $disposition
     */
    public function serve(
        UploadedDocument $uploadedDocument,
        string $disposition,
    ): RedirectResponse|StreamedResponse|Response {
        return $this->storedFileResponse->serve(
            $uploadedDocument->disk,
            $uploadedDocument->path,
            $uploadedDocument->original_filename,
            $uploadedDocument->mime_type,
            $disposition,
            self::UNTRUSTED_FILE_HEADERS,
        );
    }
}
