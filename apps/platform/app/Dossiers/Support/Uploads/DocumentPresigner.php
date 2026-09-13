<?php

declare(strict_types=1);

namespace App\Dossiers\Support\Uploads;

use Aws\S3\S3Client;
use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Config;
use RuntimeException;

use function is_string;
use function rawurlencode;

/**
 * Presigning against the documents bucket, for both reads and writes.
 *
 * The awkward part this centralises is the endpoint split. Inside Docker the
 * app talks to Floci at http://floci:4566 while the browser reaches the same
 * bucket at http://localhost:4566, and a presigned URL is only valid for the
 * host it was signed for. So when the two differ we build a second client
 * pointed at the public host and sign with that, reusing the credentials the
 * disk already resolved (IAM user in production, Floci's static pair locally).
 *
 * In every deployed environment the two are identical and the second client is
 * never built — which is exactly why the split has to be exercised locally, or
 * it only surfaces as a SignatureDoesNotMatch the first time someone uploads.
 */
final readonly class DocumentPresigner
{
    public function __construct(private FilesystemManager $filesystems) {}

    /**
     * Whether the given disk can be presigned at all.
     *
     * The local disk cannot, which is why downloads keep a streaming path and
     * why the test suite runs without an S3 endpoint.
     */
    public function supports(string $disk): bool
    {
        return Config::get('filesystems.disks.'.$disk.'.driver') === 's3';
    }

    /**
     * Presign a GET against the browser-facing endpoint.
     *
     * Returns null whenever the split does not apply — matching endpoints, a
     * non-S3 adapter, or no bucket configured — which leaves the caller on the
     * adapter's own temporaryUrl(). A missing bucket in particular must fall
     * through rather than throw: AWS_BUCKET carries no default, so an
     * environment that has not set it would otherwise turn every document
     * download into a 500 instead of a signed URL the adapter can still build.
     *
     * Takes the resolved adapter rather than a disk name because the caller
     * already has one, and resolving a second time makes the number of
     * FilesystemManager::disk() calls a detail this method leaks.
     *
     * @param  array<string, string>  $overrides  ResponseContentDisposition / ResponseContentType
     */
    public function presignGet(
        FilesystemAdapter $adapter,
        string $path,
        DateTimeInterface $expiresAt,
        array $overrides = [],
    ): ?string {
        $client = $this->publicClient($adapter);
        $bucket = $this->configuredBucket();

        if (! $client instanceof S3Client || $bucket === null) {
            return null;
        }

        $command = $client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $path,
            ...$overrides,
        ]);

        return (string) $client->createPresignedRequest($command, $expiresAt)->getUri();
    }

    /**
     * Presign a PUT the browser can upload to directly.
     *
     * Content-Type is passed through to the PutObject command so S3 records
     * something sensible on the object, but note that it is *not* covered by
     * the signature: a presigned S3 URL signs `host` and nothing else, so a
     * client can send any Content-Type it likes. Nothing downstream trusts it —
     * ClaimUploadTicketAction derives the stored mime type from the sealed
     * extension instead.
     *
     * Deliberately no server-side-encryption headers. The bucket carries a
     * default SSE-KMS configuration (infra/src/shared/documents.ts), so S3
     * encrypts the object with the OCR key whether or not the request asks it
     * to.
     */
    public function presignPut(
        string $disk,
        string $path,
        string $contentType,
        DateTimeInterface $expiresAt,
    ): string {
        $publicClient = $this->publicClient($this->adapter($disk));

        if ($publicClient instanceof S3Client) {
            $command = $publicClient->getCommand('PutObject', [
                'Bucket' => $this->bucket(),
                'Key' => $path,
                'ContentType' => $contentType,
            ]);

            return (string) $publicClient->createPresignedRequest($command, $expiresAt)->getUri();
        }

        // Laravel's own signer for the ordinary case, which is every deployed
        // environment. Going through the adapter rather than the SDK is also
        // what lets tests register buildTemporaryUploadUrlsUsing() instead of
        // needing a live bucket to exercise ticket issuing at all.
        $signed = $this->adapter($disk)->temporaryUploadUrl(
            $path,
            $expiresAt,
            ['ContentType' => $contentType],
        );

        return $signed['url'];
    }

    /**
     * Read back what was actually stored.
     *
     * This is the whole point of the claim step: the size and content type a
     * ticket was issued for are the client's claims, and the client is the one
     * doing the uploading. Only the stored object settles what arrived.
     *
     * Deliberately goes through the filesystem adapter rather than the S3
     * client. `size()` and `mimeType()` are one HeadObject each on S3, exactly
     * as a direct call would be, and going through the abstraction is what lets
     * the whole claim path run under Storage::fake() — the alternative is a
     * security check that only executes against a live bucket.
     *
     * @return array{size: int, contentType: string}|null null when the object is absent
     */
    public function storedObject(string $disk, string $path): ?array
    {
        // mimeType() is on the adapter, not the Filesystem contract that
        // disk() advertises — and every driver this application uses is one.
        $adapter = $this->adapter($disk);

        if (! $adapter->exists($path)) {
            return null;
        }

        $contentType = $adapter->mimeType($path);

        return [
            'size' => $adapter->size($path),
            'contentType' => is_string($contentType) && $contentType !== ''
                ? $contentType
                : 'application/octet-stream',
        ];
    }

    /**
     * Relocate a staged object into the dossier tree.
     *
     * Flysystem's move() copies with an ACL and reads the source object's ACL
     * first so it can retain visibility. Our documents bucket uses
     * BucketOwnerEnforced, so ACL APIs are off limits — CopyObject must omit
     * ACL entirely. Same-bucket server-side copy plus delete is equivalent.
     */
    public function moveObject(string $disk, string $source, string $destination): void
    {
        $adapter = $this->adapter($disk);

        if (! $adapter instanceof AwsS3V3Adapter) {
            throw_unless($adapter->move($source, $destination), RuntimeException::class, 'Failed to move uploaded object.');

            return;
        }

        $client = $adapter->getClient();
        $bucket = $this->bucket();

        $client->copyObject([
            'Bucket' => $bucket,
            'Key' => $destination,
            'CopySource' => rawurlencode($bucket.'/'.$source),
            'MetadataDirective' => 'COPY',
        ]);

        $client->deleteObject([
            'Bucket' => $bucket,
            'Key' => $source,
        ]);
    }

    /**
     * The bucket every presigned request targets.
     *
     * AWS_BUCKET carries no default, so the key exists as null when unset and
     * a default handed to Config::string() would be ignored (.ai/rules/app.md).
     */
    public function bucket(): string
    {
        $bucket = $this->configuredBucket();

        throw_unless($bucket !== null, RuntimeException::class, 'No S3 bucket is configured.');

        return $bucket;
    }

    /**
     * AWS_BUCKET carries no default, so the key exists as null when unset and
     * a default handed to Config::string() would be ignored (.ai/rules/app.md).
     */
    private function configuredBucket(): ?string
    {
        $bucket = Config::get('filesystems.disks.s3.bucket');

        return is_string($bucket) && $bucket !== '' ? $bucket : null;
    }

    /**
     * A client pointed at the browser-facing endpoint, or null when the
     * internal and public endpoints match and the disk's own client already
     * signs for the right host.
     */
    private function publicClient(FilesystemAdapter $adapter): ?S3Client
    {
        // Endpoint / public URL are optional nulls when disks share one host.
        $endpoint = Config::get('filesystems.disks.s3.endpoint');
        $publicBase = Config::get('filesystems.disks.s3.url');

        if (! is_string($endpoint) || ! is_string($publicBase) || $endpoint === $publicBase) {
            return null;
        }

        if (! $adapter instanceof AwsS3V3Adapter) {
            return null;
        }

        $original = $adapter->getClient();

        return new S3Client([
            'region' => $original->getRegion(),
            'version' => 'latest',
            'endpoint' => $publicBase,
            'use_path_style_endpoint' => Config::boolean('filesystems.disks.s3.use_path_style_endpoint', false),
            'credentials' => $original->getCredentials()->wait(),
        ]);
    }

    private function adapter(string $disk): FilesystemAdapter
    {
        $adapter = $this->filesystems->disk($disk);

        throw_unless($adapter instanceof FilesystemAdapter, RuntimeException::class, 'Disk "'.$disk.'" is not a filesystem adapter.');

        return $adapter;
    }
}
