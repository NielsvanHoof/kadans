<?php

declare(strict_types=1);

namespace App\AudioProcessing\Retry;

use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Pipelines\Contracts\PipelineRetryRunner;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

final class AudioTranscriptionPipelineRetryRunner implements PipelineRetryRunner
{
    public function type(): PipelineType
    {
        return PipelineType::AudioTranscription;
    }

    public function subjectClass(): string
    {
        return AudioRecording::class;
    }

    public function resetSubject(Model $subject): void
    {
        $subject->forceFill([
            'transcription_status' => TranscriptionStatus::Pending,
            'transcription_error' => null,
            'transcript' => null,
            'segments' => null,
            'transcribe_job_id' => null,
            'transcription_started_at' => null,
            'transcription_finished_at' => null,
        ])->saveQuietly();
    }

    public function dispatch(Pipeline $pipeline, Model $subject): void
    {
        dispatch(new ProcessAudioRecordingJob(
            $subject->getKey(),
            $subject->getAttribute('path'),
            $pipeline->id,
        ))->afterCommit();
    }
}
