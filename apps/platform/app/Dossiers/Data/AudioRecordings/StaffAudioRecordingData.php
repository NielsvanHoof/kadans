<?php

declare(strict_types=1);

namespace App\Dossiers\Data\AudioRecordings;

use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Pipelines\Data\PipelineData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Uploaded audio metadata for a dossier audio-recordings row.
 * List/live island payload — no transcript body.
 */
#[TypeScript]
final class StaffAudioRecordingData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $originalFilename,
        public readonly int $sizeBytes,
        public readonly string $uploadedAt,
        public readonly TranscriptionStatus $transcriptionStatus,
        public readonly ?string $transcriptionError,
        public readonly ?PipelineData $pipeline,
        public readonly bool $canDelete,
    ) {}

    /**
     * Build the list/live slice from a recording (loads the latest pipeline when needed).
     */
    public static function fromModel(AudioRecording $audioRecording): self
    {
        $audioRecording->loadMissing('latestPipeline.steps');

        $latestPipeline = $audioRecording->latestPipeline;

        return new self(
            id: $audioRecording->id,
            originalFilename: $audioRecording->original_filename,
            sizeBytes: $audioRecording->size_bytes,
            uploadedAt: $audioRecording->uploaded_at->toIso8601String(),
            transcriptionStatus: $audioRecording->transcription_status,
            transcriptionError: $audioRecording->transcription_error,
            pipeline: $latestPipeline === null
                ? null
                : PipelineData::fromModel($latestPipeline),
            canDelete: request()->user()?->can('delete', $audioRecording) ?? false,
        );
    }
}
