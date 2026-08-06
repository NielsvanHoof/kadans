<?php

declare(strict_types=1);

namespace App\AudioProcessing\Actions;

use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Pipelines\Actions\PipelineStateAction;
use App\Pipelines\Actions\PrepareProviderPipelineForProviderResultAction;
use App\Pipelines\Actions\ResolveProviderPipelineForSubjectAction;
use App\Pipelines\Enums\PipelineStepOperation;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

use function in_array;

/**
 * Mark an Amazon Transcribe job as permanently failed after SQS receive exhaustion.
 */
final readonly class FailTranscribeJobAction
{
    public function __construct(
        private MarkTranscribeJobFailedAction $markTranscribeJobFailed,
        private PipelineStateAction $pipelineState,
        private ResolveProviderPipelineForSubjectAction $resolveProviderPipeline,
        private PrepareProviderPipelineForProviderResultAction $prepareProviderPipeline,
        private TenantContext $tenantContext,
    ) {}

    public function handle(string $jobId, Throwable $exception): bool
    {
        $recording = AudioRecording::queryWithoutTenantScope()
            ->where('transcribe_job_id', $jobId)
            ->first();

        if (! $recording instanceof AudioRecording) {
            Log::warning('Transcription: permanent completion failure has no matching audio recording.', [
                'transcribe_job_id' => $jobId,
            ]);

            return false;
        }

        $tenant = Tenant::query()->find($recording->tenant_id);

        if (! $tenant instanceof Tenant) {
            Log::error('Transcription: permanent completion failure recording has missing tenant.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'transcribe_job_id' => $jobId,
            ]);

            return false;
        }

        return $this->tenantContext->runForTenant($tenant, function () use ($recording, $exception, $jobId): bool {
            $pipeline = $this->resolveProviderPipeline->handle(
                $recording,
                PipelineType::AudioTranscription,
                $jobId,
            );

            if (in_array($recording->transcription_status, [
                TranscriptionStatus::Completed,
                TranscriptionStatus::Failed,
            ], true)) {
                return true;
            }

            $error = mb_substr($exception->getMessage(), 0, 500);

            if ($error === '') {
                $error = 'Amazon Transcribe result processing failed permanently.';
            }

            $this->markTranscribeJobFailed->handle(
                $recording,
                $jobId,
                $error,
                'RESULT_PROCESSING_FAILED',
            );

            if ($pipeline instanceof Pipeline) {
                $pipeline = $this->prepareProviderPipeline->handle(
                    $pipeline,
                    (string) config('transcription.driver'),
                    $jobId,
                    'transcribe',
                );
                $this->pipelineState->handle(
                    $pipeline,
                    PipelineStepOperation::Fail,
                    $pipeline->current_stage,
                    errorCode: 'result_processing_failed',
                    errorMessage: $error,
                );
            }

            return true;
        });
    }
}
