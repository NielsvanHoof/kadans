<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Dossiers\Models\UploadedDocument;
use Aws\S3\S3Client;
use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

use function is_string;
use function mb_ord;
use function mb_str_split;
use function mb_strlen;
use function mb_substr;
use function pathinfo;

/**
 * Build a short-lived signed URL for a private stored document.
 *
 * When the internal S3 endpoint (e.g. http://floci:4566 inside Docker)
 * differs from the public URL browsers use (e.g. http://localhost:4566),
 * we create a dedicated S3 presigner with the public endpoint so the
 * signature matches the host the browser actually hits.
 */
final readonly class ResolveDocumentTemporaryUrlAction
{
    /**
     * Starting point for how much of the filename is kept; walked down
     * further below if the encoded header still comes out too long.
     */
    private const int MAX_FILENAME_LENGTH = 150;

    /**
     * Hard ceiling on the built Content-Disposition value itself. A fixed
     * filename-character bound isn't enough on its own: the RFC 5987
     * `filename*=UTF-8''...` form percent-encodes every non-ASCII byte, so a
     * filename of emoji or CJK characters can still expand to several times
     * MAX_FILENAME_LENGTH once encoded.
     */
    private const int MAX_CONTENT_DISPOSITION_LENGTH = 512;

    public function __construct(private FilesystemManager $filesystems) {}

    /**
     * S3-compatible disks support temporary URLs; local disk keeps streaming downloads in tests.
     */
    public function supportsTemporaryUrl(UploadedDocument $document): bool
    {
        $driver = Config::string('filesystems.disks.'.$document->disk.'.driver');

        return $driver === 's3';
    }

    public function handle(
        UploadedDocument $document,
        DateTimeInterface $expiresAt,
        string $disposition = 'attachment',
    ): string {
        $disk = $this->filesystems->disk($document->disk);

        throw_unless($disk instanceof FilesystemAdapter, RuntimeException::class, 'Disk does not support temporary URLs.');

        throw_if($disposition !== 'attachment' && $disposition !== 'inline', RuntimeException::class, 'Document URL disposition must be attachment or inline.');

        $publicUrl = $this->generatePublicPresignedUrl($disk, $document, $expiresAt, $disposition);
        if ($publicUrl !== null) {
            return $publicUrl;
        }

        return $disk->temporaryUrl(
            $document->path,
            $expiresAt,
            [
                'ResponseContentDisposition' => $this->contentDisposition($disposition, $document->original_filename),
                'ResponseContentType' => $document->mime_type,
            ],
        );
    }

    /**
     * Create a presigned URL using the public-facing endpoint directly.
     *
     * This avoids the signature mismatch that happens when the SDK signs
     * for the internal Docker hostname (floci:4566) but the browser
     * sends the request to localhost:4566.
     */
    private function generatePublicPresignedUrl(
        FilesystemAdapter $disk,
        UploadedDocument $document,
        DateTimeInterface $expiresAt,
        string $disposition,
    ): ?string {
        // Endpoint / public URL are optional nulls when disks share one host.
        $endpoint = Config::get('filesystems.disks.s3.endpoint');
        $publicBase = Config::get('filesystems.disks.s3.url');

        // Only needed when internal and public endpoints differ.
        if (! is_string($endpoint) || ! is_string($publicBase) || $endpoint === $publicBase) {
            return null;
        }

        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        // Build a presigning client that targets the public endpoint.
        $originalClient = $disk->getClient();
        $publicClient = new S3Client([
            'region' => $originalClient->getRegion(),
            'version' => 'latest',
            'endpoint' => $publicBase,
            'use_path_style_endpoint' => Config::boolean('filesystems.disks.s3.use_path_style_endpoint', false),
            'credentials' => $originalClient->getCredentials()->wait(),
        ]);

        $command = $publicClient->getCommand('GetObject', [
            'Bucket' => Config::string('filesystems.disks.s3.bucket'),
            'Key' => $document->path,
            'ResponseContentDisposition' => $this->contentDisposition($disposition, $document->original_filename),
            'ResponseContentType' => $document->mime_type,
        ]);

        return (string) $publicClient->createPresignedRequest($command, $expiresAt)->getUri();
    }

    /**
     * Build a well-formed, single Content-Disposition value from a fully
     * client-controlled filename (`getClientOriginalName()`, unvalidated).
     *
     * HeaderUtils::makeDisposition() — the same RFC 6266 builder Symfony's
     * own BinaryFileResponse uses for the (already-safe) local disk download
     * path — quoted-string-escapes the ASCII fallback, so a filename cannot
     * close the quoted parameter early and inject a second one. The real
     * filename still travels in full via the `filename*=UTF-8''...` form for
     * clients that support it.
     */
    private function contentDisposition(string $disposition, string $originalFilename): string
    {
        $filename = $this->boundedFilename($originalFilename);
        $value = HeaderUtils::makeDisposition($disposition, $filename, $this->asciiFallback($filename));

        // A fixed character bound can't predict the percent-encoded size up
        // front, so halve the filename until the built value actually fits.
        while (mb_strlen($value) > self::MAX_CONTENT_DISPOSITION_LENGTH && mb_strlen($filename) > 1) {
            $filename = mb_substr($filename, 0, (int) (mb_strlen($filename) / 2));
            $value = HeaderUtils::makeDisposition($disposition, $filename, $this->asciiFallback($filename));
        }

        return $value;
    }

    /**
     * Bounds filename length and strips path separators HeaderUtils rejects
     * outright, preserving the extension where the truncation allows it.
     */
    private function boundedFilename(string $filename): string
    {
        $filename = str_replace(['/', '\\'], '_', $filename);

        if (mb_strlen($filename) <= self::MAX_FILENAME_LENGTH) {
            return $filename;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.$extension : '';
        $base = mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, max(self::MAX_FILENAME_LENGTH - mb_strlen($suffix), 1));

        return $base.$suffix;
    }

    /**
     * Mirrors Symfony\Component\HttpFoundation\BinaryFileResponse's own
     * fallback derivation: replace every non-printable-ASCII byte (and any
     * literal "%", which HeaderUtils also rejects in a fallback) with "_".
     */
    private function asciiFallback(string $filename): string
    {
        $fallback = '';

        foreach (mb_str_split($filename) as $char) {
            $codepoint = mb_ord($char);
            $fallback .= ($char === '%' || $codepoint < 32 || $codepoint > 126) ? '_' : $char;
        }

        return $fallback;
    }
}
