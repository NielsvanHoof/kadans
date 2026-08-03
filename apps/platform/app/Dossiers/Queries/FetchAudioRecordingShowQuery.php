<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\DocumentProcessing\Data\PipelineData;
use App\Dossiers\Models\AudioRecording;

/**
 * Shape the transcript page props for Inertia.
 */
final class FetchAudioRecordingShowQuery
{
    /**
     * @return array{
     *     audio_recording: array{
     *         id: int,
     *         original_filename: string,
     *         size_bytes: int,
     *         uploaded_at: string,
     *         transcription_status: string,
     *         transcription_error: string|null,
     *         transcript: string|null,
     *         segments: array<int, array{text: string, speaker: string, start_seconds: float, end_seconds: float}>|null,
     *         pipeline: array<string, mixed>|null,
     *     },
     *     dossier: array{id: int, title: string}|null,
     *     can_download: bool
     * }
     */
    public function handle(AudioRecording $audioRecording): array
    {
        $audioRecording->load(['dossier:id,title,tenant_id', 'pipelines.steps']);

        $dossier = $audioRecording->dossier;

        return [
            'audio_recording' => [
                'id' => $audioRecording->id,
                'original_filename' => $audioRecording->original_filename,
                'size_bytes' => $audioRecording->size_bytes,
                'uploaded_at' => $audioRecording->uploaded_at->toIso8601String(),
                'transcription_status' => $audioRecording->transcription_status->value,
                'transcription_error' => $audioRecording->transcription_error,
                'transcript' => $audioRecording->transcript,
                'segments' => $audioRecording->segments,
                'pipeline' => $this->latestPipeline($audioRecording),
            ],
            'dossier' => $dossier === null ? null : [
                'id' => $dossier->id,
                'title' => $dossier->title,
            ],
            'can_download' => request()->user()?->can('download', $audioRecording) ?? false,
        ];
    }

    /**
     * Row shape is owned by PipelineData; see its generated TypeScript.
     *
     * @return array<string, mixed>|null
     */
    private function latestPipeline(AudioRecording $audioRecording): ?array
    {
        $pipeline = $audioRecording->pipelines->sortByDesc('id')->first();

        return $pipeline === null ? null : PipelineData::fromModel($pipeline)->toArray();
    }
}
