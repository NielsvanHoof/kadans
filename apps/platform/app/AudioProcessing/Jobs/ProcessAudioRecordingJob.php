<?php

declare(strict_types=1);

namespace App\AudioProcessing\Jobs;

use App\AudioProcessing\Actions\NotifyUploaderOfTranscriptionCompletedAction;
use App\AudioProcessing\Enums\TranscriptionProcessingOutcome;
use App\AudioProcessing\Services\TranscriptionOrchestrator;
use App\AudioProcessing\Transitions\AudioTranscriptionTransitions;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use App\Pipelines\Support\PipelineStepSequence;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

use function hash;

/**
 * Transcription pipeline for an audio recording.
 * Mock completes in-process; Amazon Transcribe stays processing until EventBridge/SQS completion.
 */
#[Backoff(self::BACKOFF_SECONDS)]
#[Tries(self::MAX_TRIES)]
final class ProcessAudioRecordingJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [10, 30, 60];

    /**
     * Must stay below Redis retry_after. The connection default is sized for
     * GenerateDataSubjectExportJob (600s), which is the larger timeout.
     */
    public int $timeout;

    public function __construct(
        public int $audioRecordingId,
        public ?string $expectedPath = null,
        public ?int $pipelineId = null
    ) {
        $this->timeout = Config::integer('transcription.timeouts.job', 120);
        $this->onQueue('transcription');
    }

    public function uniqueId(): string
    {
        return "{$this->audioRecordingId}:{$this->pipelineId}:".hash('sha256', $this->expectedPath ?? 'legacy');
    }

    /**
     * Auto-release the unique lock after worst-case attempts + backoff so a
     * SIGKILL / OOM cannot wedge the subject forever
     */
    public function uniqueFor(): int
    {
        return ($this->timeout * self::MAX_TRIES) + array_sum(self::BACKOFF_SECONDS);
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
        PipelineState $pipelineState,
        NotifyUploaderOfTranscriptionCompletedAction $notifyUploaderOfTranscriptionCompleted,
        AudioTranscriptionTransitions $transcriptionTransitions,
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

        $claimed = $transcriptionTransitions->claim($recording, $expectedPath);

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
        ]);

        try {
            $pipeline = $this->resolvePipeline();

            if ($pipeline instanceof Pipeline) {
                $pipelineState->start($pipeline, PipelineStepKey::Submit);
            }

            $outcome = $transcriptionOrchestrator->startProcessing($claimed);

            if ($pipeline instanceof Pipeline) {
                $this->recordOutcome($pipeline, $outcome, $claimed, $pipelineState);
            }

            if ($outcome === TranscriptionProcessingOutcome::Immediate) {
                $notifyUploaderOfTranscriptionCompleted->handle($claimed);
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

                $transcriptionTransitions->resetForRetry($claimed, $expectedPath);

                throw $exception;
            }

            $error = mb_substr($exception->getMessage(), 0, 500);
            $updated = $transcriptionTransitions->failForPath($claimed, $expectedPath, $error);

            if (! $updated) {
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

        resolve(PipelineState::class)->fail(
            $pipeline,
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

    private function recordOutcome(
        Pipeline $pipeline,
        TranscriptionProcessingOutcome $outcome,
        AudioRecording $recording,
        PipelineState $pipelineState,
    ): void {
        $pipeline->forceFill([
            'provider' => Config::string('transcription.driver'),
            'provider_job_id' => $recording->transcribe_job_id,
        ])->saveQuietly();

        if ($outcome === TranscriptionProcessingOutcome::Deferred) {
            $pipelineState->run($pipeline, fn (PipelineStepSequence $steps): PipelineStepSequence => $steps
                ->complete(PipelineStepKey::Submit)
                ->start(PipelineStepKey::Transcribe));

            return;
        }

        // The provider already finished inline, so the remaining steps are
        // bookkeeping with no work between them — one lock, one broadcast.
        $pipelineState->run($pipeline, fn (PipelineStepSequence $steps): PipelineStepSequence => $steps
            ->complete(PipelineStepKey::Submit)
            ->start(PipelineStepKey::Transcribe)
            ->complete(PipelineStepKey::Transcribe)
            ->start(PipelineStepKey::ProcessResults)
            ->complete(PipelineStepKey::ProcessResults));
    }
}
