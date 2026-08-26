<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

use App\Dossiers\Actions\DocumentRequests\ResolveDocumentTemporaryUrlAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function sprintf;

/**
 * Deliver a stored object without holding a PHP worker for the transfer.
 *
 * Octane runs in worker mode, so a streamed download occupies one of a small
 * pool of workers for the whole transfer — a handful of concurrent large files
 * is enough to stall the application. On S3 the bytes therefore come from the
 * storage host via a short-lived signed URL, and only the local/CI disk (which
 * cannot presign, and which Storage::fake() stands in for) keeps streaming.
 *
 * Extracted from UploadedDocumentResponse once audio recordings and export
 * archives needed the same branch: the interesting part is not the two lines of
 * Storage::download(), it is that the signed-URL path and the hardening headers
 * must not drift between the surfaces that serve client-supplied bytes.
 */
final readonly class StoredFileResponse
{
    /**
     * How long a signed download URL stays valid.
     *
     * Short by design: the URL is a bearer token for the object, so it should
     * outlive the redirect the browser is about to follow and not much else.
     */
    private const int URL_TTL_MINUTES = 5;

    public function __construct(
        private ResolveDocumentTemporaryUrlAction $resolveTemporaryUrl,
    ) {}

    /**
     * @param  'attachment'|'inline'  $disposition
     * @param  array<string, string>  $headers  Applied to the streamed path only; the
     *                                          signed path is served by S3, which
     *                                          sends neither these nor its own.
     */
    public function serve(
        string $disk,
        string $path,
        string $filename,
        ?string $mimeType = null,
        string $disposition = 'attachment',
        array $headers = [],
    ): RedirectResponse|StreamedResponse {
        $driver = $this->resolveTemporaryUrl->driverForDisk($disk);

        if ($driver === null) {
            // The row names a disk that is no longer configured, so the bytes
            // are unreachable. Fail closed as a miss rather than a 500: the
            // caller cannot act on it either way, but operators still need to
            // know a row outlived its disk, so it is reported.
            report(new RuntimeException(sprintf(
                'Stored file "%s" is on disk "%s", which is not configured.',
                $path,
                $disk,
            )));

            abort(404);
        }

        if ($driver === 's3') {
            return redirect()->away($this->resolveTemporaryUrl->forStoredFile(
                $disk,
                $path,
                $filename,
                $mimeType,
                now()->addMinutes(self::URL_TTL_MINUTES),
                $disposition,
            ));
        }

        // Local/CI disks keep streaming through Laravel (Storage::fake friendly).
        if ($disposition === 'inline') {
            return Storage::disk($disk)->response(
                $path,
                $filename,
                $mimeType === null ? $headers : ['Content-Type' => $mimeType] + $headers,
            );
        }

        return Storage::disk($disk)->download($path, $filename, $headers);
    }
}
