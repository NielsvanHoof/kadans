<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Drivers;

use App\AudioProcessing\Contracts\TranscribesAudio;
use App\AudioProcessing\Data\TranscriptionResult;
use App\Dossiers\Models\AudioRecording;

/**
 * POC / CI stand-in for a real speech-to-text provider.
 * Returns a deterministic transcript so demos and tests stay stable offline.
 */
final class MockAudioTranscriber implements TranscribesAudio
{
    public function transcribe(AudioRecording $recording): TranscriptionResult
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
