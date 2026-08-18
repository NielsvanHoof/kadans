<?php

declare(strict_types=1);

namespace App\AudioProcessing\Runners;

use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Pipelines\Contracts\PipelineRunner;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class AudioTranscriptionPipelineRunner implements PipelineRunner
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
        throw_unless($subject instanceof AudioRecording, RuntimeException::class, 'The audio transcription pipeline runner received an unexpected subject.');

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
        throw_unless($subject instanceof AudioRecording, RuntimeException::class, 'The audio transcription pipeline runner received an unexpected subject.');

        dispatch(new ProcessAudioRecordingJob(
            $subject->id,
            $subject->path,
            $pipeline->id,
        ))->afterCommit();
    }
}
