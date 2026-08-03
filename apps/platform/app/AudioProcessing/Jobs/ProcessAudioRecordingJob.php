<?php

declare(strict_types=1);

namespace App\AudioProcessing\Jobs;

use App\AudioProcessing\Actions\NotifyUploaderOfTranscriptionCompletedAction;
use App\AudioProcessing\Services\TranscriptionOrchestrator;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function hash;

/**
 * Synchronous transcription pipeline for an audio recording.
 * Unlike Textract, the transcription call itself always completes within this job.
 */
#[Backoff([10, 30, 60])]
#[Tries(self::MAX_TRIES)]
final class ProcessAudioRecordingJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    public function __construct(
        public int $audioRecordingId,
        public ?string $expectedPath = null,
        public ?int $pipelineId = null
    ) {}

    public function uniqueId(): string
    {
        return "{$this->audioRecordingId}:{$this->pipelineId}:".hash('sha256', $this->expectedPath ?? 'legacy');
    }

    /**
     * Telescope / Horizon searchable tags (jobs carry IDs, not Eloquent models).
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = [
            'transcription',
            'audio_recording:'.$this->audioRecordingId,
        ];

        if ($this->pipelineId !== null) {
            $tags[] = 'pipeline:'.$this->pipelineId;
        }

        return $tags;
    }

    public function handle(
        TranscriptionOrchestrator $transcriptionOrchestrator,
        LogAuditActivityAction $logAuditActivity,
        PipelineStateAction $pipelineState,
        NotifyUploaderOfTranscriptionCompletedAction $notifyUploaderOfTranscriptionCompleted,
    ): void {
        $recording = AudioRecording::query()->findOrFail($this->audioRecordingId);

        $expectedPath = $this->expectedPath ?? $recording->path;

        if ($recording->transcription_status === TranscriptionStatus::Completed) {
            Log::info('Transcription: skipped ProcessAudioRecordingJob — already completed.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
            ]);

            return;
        }

        $claimed = $this->claimForProcessing($recording, $expectedPath);

        if (! $claimed instanceof AudioRecording) {
            Log::info('Transcription: skipped ProcessAudioRecordingJob — could not claim recording.', [
                'audio_recording_id' => $this->audioRecordingId,
                'expected_path' => $expectedPath,
            ]);

            return;
        }

        Log::info('Transcription: ProcessAudioRecordingJob claimed recording.', [
            'audio_recording_id' => $claimed->id,
            'tenant_id' => $claimed->tenant_id,
            'attempt' => $this->attempts(),
            'original_filename' => $claimed->original_filename,
        ]);

        try {
            $pipeline = $this->resolvePipeline();

            if ($pipeline instanceof Pipeline) {
                $pipelineState->handle($pipeline, PipelineStepOperation::Start, 'submit');
                $pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'submit');
                $pipelineState->handle($pipeline, PipelineStepOperation::Start, 'transcribe');
            }

            $transcript = $transcriptionOrchestrator->transcribe($claimed);

            $updated = AudioRecording::query()
                ->whereKey($claimed->id)
                ->where('path', $expectedPath)
                ->update([
                    'transcription_status' => TranscriptionStatus::Completed->value,
                    'transcript' => $transcript,
                    'transcription_error' => null,
                    'transcription_finished_at' => now(),
                ]);

            if ($updated !== 1) {
                Log::warning('Transcription: discarded completion for a stale upload revision.', [
                    'audio_recording_id' => $claimed->id,
                    'tenant_id' => $claimed->tenant_id,
                    'expected_path' => $expectedPath,
                ]);

                return;
            }

            $notifyUploaderOfTranscriptionCompleted->handle($claimed);

            if ($pipeline instanceof Pipeline) {
                $pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'transcribe');
                $pipelineState->handle($pipeline, PipelineStepOperation::Start, 'process_results');
                $pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'process_results');
            }
        } catch (Throwable $exception) {
            // Let the queue retry until tries are exhausted.
            if ($this->attempts() < self::MAX_TRIES) {
                Log::warning('Transcription: ProcessAudioRecordingJob failed — will retry.', [
                    'audio_recording_id' => $claimed->id,
                    'tenant_id' => $claimed->tenant_id,
                    'attempt' => $this->attempts(),
                    'tries' => self::MAX_TRIES,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $error = mb_substr($exception->getMessage(), 0, 500);
            $updated = AudioRecording::query()
                ->whereKey($claimed->id)
                ->where('path', $expectedPath)
                ->update([
                    'transcription_status' => TranscriptionStatus::Failed->value,
                    'transcription_error' => $error,
                    'transcription_finished_at' => now(),
                ]);

            if ($updated !== 1) {
                Log::warning('Transcription: discarded permanent failure for a stale upload revision.', [
                    'audio_recording_id' => $claimed->id,
                    'tenant_id' => $claimed->tenant_id,
                    'expected_path' => $expectedPath,
                    'error' => $error,
                ]);

                throw $exception;
            }

            $claimed->refresh();

            Log::error('Transcription: ProcessAudioRecordingJob failed permanently.', [
                'audio_recording_id' => $claimed->id,
                'tenant_id' => $claimed->tenant_id,
                'attempt' => $this->attempts(),
                'error' => $error,
            ]);

            $logAuditActivity->handle(
                AuditEvent::AudioTranscriptionFailed,
                $claimed,
                [
                    'original_filename' => $claimed->original_filename,
                    'error' => $claimed->transcription_error,
                ],
                includeRequestContext: false,
            );

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $pipeline = $this->resolvePipeline();

        // failed() is invoked as $job->failed($e) — resolve deps from the container.
        if (! $pipeline instanceof Pipeline || $pipeline->status->isTerminal()) {
            return;
        }

        resolve(PipelineStateAction::class)->handle(
            $pipeline,
            PipelineStepOperation::Fail,
            $pipeline->current_stage,
            $exception,
            errorCode: 'transcription_job_failed',
        );
    }

    private function resolvePipeline(): ?Pipeline
    {
        if ($this->pipelineId === null) {
            return null;
        }

        return Pipeline::query()->find($this->pipelineId);
    }

    /**
     * Atomically move pending/failed → processing.
     * Returns null when the recording cannot be claimed (stale path, already
     * completed, or another worker already claimed it).
     */
    private function claimForProcessing(
        AudioRecording $recording,
        string $expectedPath,
    ): ?AudioRecording {
        return DB::transaction(function () use ($recording, $expectedPath): ?AudioRecording {
            $query = AudioRecording::query()->whereKey($recording->getKey());
            $query->getQuery()->lockForUpdate();

            $locked = $query->firstOrFail();

            // Stale job for a replaced upload revision — skip without failing the queue.
            if ($locked->path !== $expectedPath) {
                return null;
            }

            // Another worker already finished or is currently processing this revision.
            if (
                $locked->transcription_status === TranscriptionStatus::Completed
                || $locked->transcription_status === TranscriptionStatus::Processing
            ) {
                return null;
            }

            $locked->forceFill([
                'transcription_status' => TranscriptionStatus::Processing,
                'transcription_error' => null,
                'transcript' => null,
                'transcription_started_at' => $locked->transcription_started_at ?? now(),
                'transcription_finished_at' => null,
            ])->save();

            return $locked;
        });
    }
}
