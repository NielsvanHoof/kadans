<?php

declare(strict_types=1);

namespace App\AudioProcessing\Actions;

use App\AudioProcessing\Services\Transcribe\TranscribeTranscriptMapper;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\AudioRecording;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function mb_ltrim;
use function mb_strlen;
use function mb_strtoupper;
use function mb_substr;
use function parse_url;
use function rawurldecode;
use function str_starts_with;

/**
 * Applies an Amazon Transcribe completion notification to an AudioRecording.
 */
final readonly class CompleteTranscribeJobAction
{
    public function __construct(
        private TranscribeServiceClient $transcribe,
        private TranscribeTranscriptMapper $mapper,
        private MarkTranscribeJobFailedAction $markTranscribeJobFailed,
        private NotifyUploaderOfTranscriptionCompletedAction $notifyUploaderOfTranscriptionCompleted,
        private LogAuditActivityAction $logAuditActivity,
        private PipelineStateAction $pipelineState,
        private ResolveTranscriptionPipelineForRecordingAction $resolveTranscriptionPipeline,
        private PrepareTranscriptionPipelineForProviderResultAction $prepareTranscriptionPipeline,
        private TenantContext $tenantContext,
        private FilesystemManager $filesystems,
        private Repository $config,
    ) {}

    /**
     * @param  array{JobName?: mixed, TranscriptionJobName?: mixed, Status?: mixed, TranscriptionJobStatus?: mixed, FailureReason?: mixed}  $notification
     */
    public function handle(array $notification): bool
    {
        $jobId = $this->jobName($notification);
        $status = $this->jobStatus($notification);

        if ($jobId === null) {
            Log::warning('Transcription: Transcribe completion ignored — missing job name.');

            return false;
        }

        if ($status === null) {
            Log::warning('Transcription: Transcribe completion ignored — missing status.', [
                'transcribe_job_id' => $jobId,
            ]);

            return false;
        }

        $recording = AudioRecording::queryWithoutTenantScope()
            ->where('transcribe_job_id', $jobId)
            ->first();

        if (! $recording instanceof AudioRecording) {
            Log::warning('Transcription: Transcribe completion has no matching audio recording.', [
                'transcribe_job_id' => $jobId,
                'status' => $status,
            ]);

            return false;
        }

        $tenant = Tenant::query()->find($recording->tenant_id);

        if (! $tenant instanceof Tenant) {
            Log::error('Transcription: Transcribe completion recording has missing tenant.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'transcribe_job_id' => $jobId,
            ]);

            return false;
        }

        return $this->tenantContext->runForTenant($tenant, function () use ($recording, $notification, $jobId, $status): bool {
            $pipeline = $this->resolveTranscriptionPipeline->handle($recording, $jobId);

            if (in_array($recording->transcription_status, [
                TranscriptionStatus::Completed,
                TranscriptionStatus::Failed,
            ], true)) {
                if (
                    $recording->transcription_status === TranscriptionStatus::Completed
                    && $pipeline instanceof Pipeline
                    && ! $pipeline->status->isTerminal()
                ) {
                    $pipeline = $this->prepareTranscriptionPipeline->handle(
                        $pipeline,
                        (string) config('transcription.driver'),
                        $jobId,
                    );
                    $this->pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'transcribe');
                    $this->pipelineState->handle($pipeline, PipelineStepOperation::Start, 'process_results');
                    $this->pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'process_results');
                }

                Log::info('Transcription: Transcribe completion already applied — skipping.', [
                    'audio_recording_id' => $recording->id,
                    'tenant_id' => $recording->tenant_id,
                    'transcribe_job_id' => $jobId,
                ]);

                return true;
            }

            Log::info('Transcription: applying Transcribe completion notification.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'transcribe_job_id' => $jobId,
                'status' => $status,
            ]);

            if (in_array($status, ['COMPLETED', 'SUCCEEDED'], true)) {
                return $this->completeSuccessfully($recording, $pipeline, $jobId);
            }

            if (in_array($status, ['FAILED', 'ERROR'], true)) {
                $message = $notification['FailureReason'] ?? "Amazon Transcribe job reported {$status}.";
                $error = is_string($message) && $message !== ''
                    ? mb_substr($message, 0, 500)
                    : "Amazon Transcribe job reported {$status}.";

                $this->markTranscribeJobFailed->handle($recording, $jobId, $error, $status);

                if ($pipeline instanceof Pipeline) {
                    $this->pipelineState->handle(
                        $pipeline,
                        PipelineStepOperation::Fail,
                        'transcribe',
                        errorCode: 'transcribe_provider_failed',
                        errorMessage: $error,
                    );
                }

                return true;
            }

            // IN_PROGRESS / QUEUED — leave the SQS message for a later poll.
            Log::info('Transcription: Transcribe job not terminal yet — deferring.', [
                'audio_recording_id' => $recording->id,
                'transcribe_job_id' => $jobId,
                'status' => $status,
            ]);

            return false;
        });
    }

    private function completeSuccessfully(
        AudioRecording $recording,
        ?Pipeline $pipeline,
        string $jobId,
    ): bool {
        if ($pipeline instanceof Pipeline) {
            $pipeline = $this->prepareTranscriptionPipeline->handle(
                $pipeline,
                (string) config('transcription.driver'),
                $jobId,
            );
            $this->pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'transcribe');
            $this->pipelineState->handle($pipeline, PipelineStepOperation::Start, 'process_results');
        }

        $startedAt = hrtime(true);
        $result = $this->mapper->map($this->fetchTranscriptPayload($jobId));
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $updated = AudioRecording::query()
            ->whereKey($recording->id)
            ->where('path', $recording->path)
            ->where('transcribe_job_id', $jobId)
            ->where('transcription_status', TranscriptionStatus::Processing->value)
            ->update([
                'transcription_status' => TranscriptionStatus::Completed->value,
                'transcript' => $result->text,
                'segments' => json_encode($result->segments),
                'transcription_error' => null,
                'transcription_finished_at' => now(),
            ]);

        if ($updated !== 1) {
            Log::warning('Transcription: discarded stale Transcribe completion.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'transcribe_job_id' => $jobId,
                'expected_path' => $recording->path,
            ]);

            return true;
        }

        $recording->refresh();

        if ($pipeline instanceof Pipeline) {
            $this->pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'process_results');
        }

        $this->notifyUploaderOfTranscriptionCompleted->handle($recording);

        $this->logAuditActivity->handle(
            AuditEvent::AudioTranscriptionCompleted,
            $recording,
            [
                'original_filename' => $recording->original_filename,
                'transcript_length' => mb_strlen($result->text),
                'transcribe_job_id' => $jobId,
            ],
            includeRequestContext: false,
        );

        Log::info('Transcription: Amazon Transcribe completed successfully.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
            'transcribe_job_id' => $jobId,
            'transcript_length' => mb_strlen($result->text),
            'duration_ms' => $durationMs,
        ]);

        return true;
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
            return rawurldecode(mb_substr($uri, mb_strlen("s3://{$bucket}/")));
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
            return rawurldecode(mb_substr($path, mb_strlen("{$bucket}/")));
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

    /**
     * @param  array<string, mixed>  $notification
     */
    private function jobName(array $notification): ?string
    {
        foreach (['JobName', 'TranscriptionJobName'] as $key) {
            $value = $notification[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function jobStatus(array $notification): ?string
    {
        foreach (['Status', 'TranscriptionJobStatus'] as $key) {
            $value = $notification[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return mb_strtoupper($value);
            }
        }

        return null;
    }
}
