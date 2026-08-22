<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

use App\Dossiers\Actions\DocumentRequests\ResolveDocumentTemporaryUrlAction;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function sprintf;

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
        private ResolveDocumentTemporaryUrlAction $resolveDocumentTemporaryUrl,
    ) {}

    /**
     * Redirect to a signed S3 URL, or stream from the local/CI disk.
     *
     * @param  'attachment'|'inline'  $disposition
     */
    public function serve(
        UploadedDocument $uploadedDocument,
        string $disposition,
    ): RedirectResponse|StreamedResponse {
        $driver = $this->resolveDocumentTemporaryUrl->diskDriver($uploadedDocument);

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
            $url = $this->resolveDocumentTemporaryUrl->handle(
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
