<?php

declare(strict_types=1);

namespace App\AudioProcessing\Actions;

use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Pipelines\Actions\PrepareProviderPipelineForProviderResultAction;
use App\Pipelines\Actions\ResolveProviderPipelineForSubjectAction;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

use function in_array;

/**
 * Mark an Amazon Transcribe job as permanently failed after the apply job's retries are exhausted.
 *
 * Tenant is already current: ApplyTranscribeResultJob is tenant-aware, and
 * failed() runs before Spatie forgets the tenant on JobExceptionOccurred.
 */
final readonly class FailTranscribeJobAction
{
    public function __construct(
        private MarkTranscribeJobFailedAction $markTranscribeJobFailed,
        private PipelineState $pipelineState,
        private ResolveProviderPipelineForSubjectAction $resolveProviderPipeline,
        private PrepareProviderPipelineForProviderResultAction $prepareProviderPipeline,
    ) {}

    public function handle(string $jobId, Throwable $exception): bool
    {
        $recording = AudioRecording::query()
            ->where('transcribe_job_id', $jobId)
            ->first();

        if (! $recording instanceof AudioRecording) {
            Log::warning('Transcription: permanent completion failure has no matching audio recording.', [
                'transcribe_job_id' => $jobId,
            ]);

            return false;
        }

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
                Config::string('transcription.driver'),
                $jobId,
                PipelineStepKey::Transcribe,
            );
            $this->pipelineState->fail(
                $pipeline,
                $pipeline->current_stage,
                errorCode: 'result_processing_failed',
                errorMessage: $error,
            );
        }

        return true;
    }
}
