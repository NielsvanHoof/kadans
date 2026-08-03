<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Drivers;

use App\AudioProcessing\Contracts\TranscribesAudio;
use App\Dossiers\Models\AudioRecording;

/**
 * POC / CI stand-in for a real speech-to-text provider.
 * Returns a deterministic transcript so demos and tests stay stable offline.
 */
final class MockAudioTranscriber implements TranscribesAudio
{
    public function transcribe(AudioRecording $recording): string
    {
        return "Mock transcript for {$recording->original_filename}.";
    }
}
