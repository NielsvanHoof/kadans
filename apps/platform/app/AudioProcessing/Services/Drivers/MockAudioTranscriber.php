<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Drivers;

use App\AudioProcessing\Contracts\AudioTranscriber;
use App\AudioProcessing\Data\TranscriptionResult;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Support\Facades\Log;

/**
 * CI stand-in for Amazon Transcribe.
 * Completes synchronously inside start() so demos and tests stay offline-stable.
 */
final class MockAudioTranscriber implements AudioTranscriber
{
    public function start(AudioRecording $recording): void
    {
        $result = $this->buildResult($recording);

        $updated = AudioRecording::query()
            ->whereKey($recording->id)
            ->where('path', $recording->path)
            ->where('transcription_status', TranscriptionStatus::Processing->value)
            ->update([
                'transcription_status' => TranscriptionStatus::Completed->value,
                'transcript' => $result->text,
                'segments' => json_encode($result->segments),
                'transcription_error' => null,
                'transcribe_job_id' => null,
                'transcription_finished_at' => now(),
            ]);

        if ($updated !== 1) {
            Log::warning('Transcription: discarded mock result for a stale upload revision.', [
                'audio_recording_id' => $recording->id,
                'tenant_id' => $recording->tenant_id,
                'expected_path' => $recording->path,
            ]);

            return;
        }

        $recording->refresh();

        Log::info('Transcription: mock driver completed.', [
            'audio_recording_id' => $recording->id,
            'tenant_id' => $recording->tenant_id,
        ]);
    }

    private function buildResult(AudioRecording $recording): TranscriptionResult
    {
        $text = "Mock transcript for {$recording->original_filename}.";

        return new TranscriptionResult(
            text: $text,
            segments: [[
                'text' => $text,
                'speaker' => 'Speaker 1',
                'start_seconds' => 0.0,
                'end_seconds' => 3.0,
            ]],
        );
    }
}
