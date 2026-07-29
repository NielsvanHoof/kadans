<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Dossiers\Models\UploadedDocument;
use Aws\S3\S3Client;
use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

use function is_string;

/**
 * Build a short-lived signed URL for a private stored document.
 *
 * When the internal S3 endpoint (e.g. http://minio:9000 inside Docker)
 * differs from the public URL browsers use (e.g. http://localhost:9000),
 * we create a dedicated S3 presigner with the public endpoint so the
 * signature matches the host the browser actually hits.
 */
final readonly class ResolveDocumentTemporaryUrlAction
{
    public function __construct(private FilesystemManager $filesystems) {}

    /**
     * S3-compatible disks support temporary URLs; local disk keeps streaming downloads in tests.
     */
    public function supportsTemporaryUrl(UploadedDocument $document): bool
    {
        $driver = config('filesystems.disks.'.$document->disk.'.driver');

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
                'ResponseContentDisposition' => $disposition.'; filename="'.$document->original_filename.'"',
                'ResponseContentType' => $document->mime_type,
            ],
        );
    }

    /**
     * Create a presigned URL using the public-facing endpoint directly.
     *
     * This avoids the signature mismatch that happens when the SDK signs
     * for the internal Docker hostname (minio:9000) but the browser
     * sends the request to localhost:9000.
     */
    private function generatePublicPresignedUrl(
        FilesystemAdapter $disk,
        UploadedDocument $document,
        DateTimeInterface $expiresAt,
        string $disposition,
    ): ?string {
        $endpoint = config('filesystems.disks.s3.endpoint');
        $publicBase = config('filesystems.disks.s3.url');

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
            'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint', false),
            'credentials' => $originalClient->getCredentials()->wait(),
        ]);

        $command = $publicClient->getCommand('GetObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $document->path,
            'ResponseContentDisposition' => $disposition.'; filename="'.$document->original_filename.'"',
            'ResponseContentType' => $document->mime_type,
        ]);

        return (string) $publicClient->createPresignedRequest($command, $expiresAt)->getUri();
    }
}
