<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Actions\Pipelines;

use App\Accounts\Models\User;
use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Transitions\PipelineTransitions;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-queue a failed or stuck run on its existing pipeline row.
 *
 * The subject reset is not cosmetic. Both processing jobs claim their subject
 * before doing any work and skip silently when it is already `processing` with
 * a provider job id in flight — exactly the state a stuck run leaves behind.
 * Without clearing that, a retry would look successful and then do nothing.
 */
final readonly class RetryPipelineAction
{
    public function __construct(
        private PipelineTransitions $transitions,
        private BroadcastPipelineUpdatedAction $broadcastPipelineUpdated,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(Pipeline $pipeline, User $user): Pipeline
    {
        /** @var array{0: Pipeline, 1: Model} $result */
        $result = DB::transaction(function () use ($pipeline, $user): array {
            $pipelineQuery = Pipeline::query()->whereKey($pipeline->id);
            $pipelineQuery->getQuery()->lockForUpdate();
            $lockedPipeline = $pipelineQuery->firstOrFail();

            $previousStatus = $lockedPipeline->status;
            $subject = $this->resolveSubject($lockedPipeline);

            $this->transitions->retry($lockedPipeline);
            $this->resetSubject($lockedPipeline->type, $subject);

            $this->logAuditActivity->handle(
                AuditEvent::PipelineRetried,
                $lockedPipeline,
                [
                    'pipeline_type' => $lockedPipeline->type->value,
                    'previous_status' => $previousStatus->value,
                    'attempt_number' => $lockedPipeline->attempt_number,
                ],
                causer: $user,
            );

            return [$lockedPipeline, $subject];
        });

        [$retried, $subject] = $result;

        // Queue only once the reset is durable, so a worker cannot pick the run
        // up while the subject still looks claimed by the previous attempt.
        $this->dispatchRun($retried, $subject);
        $this->broadcastPipelineUpdated->handle($retried->load('steps'));

        return $retried;
    }

    /**
     * A run whose subject has gone away can never make progress, so refuse it
     * rather than queueing a job that is guaranteed to no-op.
     */
    private function resolveSubject(Pipeline $pipeline): Model
    {
        $pipeline->loadMissing('pipelineable');
        $subject = $pipeline->pipelineable;

        $expected = match ($pipeline->type) {
            PipelineType::OcrDocument => UploadedDocument::class,
            PipelineType::AudioTranscription => AudioRecording::class,
            PipelineType::DossierExport => DossierExport::class,
        };

        if (! $subject instanceof $expected) {
            throw ValidationException::withMessages([
                'pipeline' => 'This run can no longer be retried because its subject is unavailable.',
            ]);
        }

        return $subject;
    }

    private function resetSubject(PipelineType $type, Model $subject): void
    {
        $attributes = match ($type) {
            PipelineType::OcrDocument => [
                'processing_status' => DocumentProcessingStatus::Pending,
                'processing_error' => null,
                'extracted_text' => null,
                'textract_job_id' => null,
                'processing_started_at' => null,
                'processing_finished_at' => null,
            ],
            PipelineType::AudioTranscription => [
                'transcription_status' => TranscriptionStatus::Pending,
                'transcription_error' => null,
                'transcript' => null,
                'segments' => null,
                'transcribe_job_id' => null,
                'transcription_started_at' => null,
                'transcription_finished_at' => null,
            ],
            // `disk` and `path` are left alone: GenerateDossierExportAction
            // overwrites them on success, and `isDownloadable()` already fails
            // while the export is not completed.
            PipelineType::DossierExport => [
                'status' => DossierExportStatus::Pending,
                'error' => null,
                'expires_at' => null,
                'completed_at' => null,
                'failed_at' => null,
            ],
        };

        $subject->forceFill($attributes)->saveQuietly();
    }

    private function dispatchRun(Pipeline $pipeline, Model $subject): void
    {
        $job = match ($pipeline->type) {
            PipelineType::OcrDocument => new ProcessUploadedDocumentJob(
                $subject->getKey(),
                $subject->getAttribute('path'),
                $pipeline->id,
            ),
            PipelineType::AudioTranscription => new ProcessAudioRecordingJob(
                $subject->getKey(),
                $subject->getAttribute('path'),
                $pipeline->id,
            ),
            PipelineType::DossierExport => new GenerateDossierExportJob($pipeline->id),
        };

        dispatch($job)->afterCommit();
    }
}
