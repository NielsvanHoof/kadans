<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Transcribe;

use App\AudioProcessing\Data\TranscriptionResult;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

use function is_array;
use function is_string;
use function json_decode;
use function mb_ltrim;
use function parse_url;
use function rawurldecode;
use function str_starts_with;

/**
 * Fetch an Amazon Transcribe job result and map it into our TranscriptionResult shape.
 */
final readonly class TranscribeExtractionPipeline
{
    public function __construct(
        private TranscribeServiceClient $transcribe,
        private TranscribeTranscriptMapper $mapper,
        private FilesystemManager $filesystems,
        private Repository $config,
    ) {}

    public function run(string $jobId): TranscriptionResult
    {
        return $this->mapper->map($this->fetchTranscriptPayload($jobId));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTranscriptPayload(string $jobId): array
    {
        $job = $this->transcribe->getTranscriptionJob([
            'TranscriptionJobName' => $jobId,
        ]);

        $transcriptionJob = $job->get('TranscriptionJob');

        throw_unless(is_array($transcriptionJob), RuntimeException::class, "Amazon Transcribe job [{$jobId}] payload was invalid.");

        $transcript = $transcriptionJob['Transcript'] ?? null;

        throw_unless(is_array($transcript), RuntimeException::class, "Amazon Transcribe job [{$jobId}] has no transcript block.");

        $uri = $transcript['TranscriptFileUri'] ?? null;

        throw_unless(is_string($uri) && $uri !== '', RuntimeException::class, "Amazon Transcribe job [{$jobId}] has no TranscriptFileUri.");

        // Output lands in our private KMS bucket — Http::get cannot read it.
        $body = $this->downloadTranscriptBody($uri, $jobId);

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        throw_unless(is_array($decoded), RuntimeException::class, "Amazon Transcribe transcript JSON for [{$jobId}] was invalid.");

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function downloadTranscriptBody(string $uri, string $jobId): string
    {
        $bucket = $this->config->get('transcription.transcribe.bucket');
        $objectKey = is_string($bucket) && $bucket !== ''
            ? $this->objectKeyForConfiguredBucket($uri, $bucket)
            : null;

        if (is_string($objectKey)) {
            $contents = $this->filesystems->disk('s3')->get($objectKey);

            throw_unless(
                is_string($contents) && $contents !== '',
                RuntimeException::class,
                "Failed to download Amazon Transcribe transcript for [{$jobId}] from s3://{$bucket}/{$objectKey}.",
            );

            return $contents;
        }

        // Service-managed temporary URIs (no OutputBucketName) remain HTTP-fetchable.
        $response = Http::timeout(60)->get($uri);

        throw_unless($response->successful(), RuntimeException::class, "Failed to download Amazon Transcribe transcript for [{$jobId}].");

        return $response->body();
    }

    /**
     * Extract the object key when TranscriptFileUri points at our configured bucket.
     */
    private function objectKeyForConfiguredBucket(string $uri, string $bucket): ?string
    {
        if (str_starts_with($uri, "s3://{$bucket}/")) {
            return rawurldecode(Str::after($uri, "s3://{$bucket}/"));
        }

        $parts = parse_url($uri);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || ! is_string($parts['path'] ?? null)) {
            return null;
        }

        $host = $parts['host'];
        $path = mb_ltrim($parts['path'], '/');

        // https://s3.eu-west-1.amazonaws.com/bucket/key
        if (
            (str_starts_with($host, 's3.') || str_starts_with($host, 's3-'))
            && str_starts_with($path, "{$bucket}/")
        ) {
            return rawurldecode(Str::after($path, "{$bucket}/"));
        }

        // https://bucket.s3.eu-west-1.amazonaws.com/key
        if (
            $host === "{$bucket}.s3.amazonaws.com"
            || str_starts_with($host, "{$bucket}.s3.")
            || str_starts_with($host, "{$bucket}.s3-")
        ) {
            return rawurldecode($path);
        }

        return null;
    }
}
