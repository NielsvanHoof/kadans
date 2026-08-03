<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Drivers;

use App\AudioProcessing\Contracts\TranscribesAudio;
use App\Dossiers\Models\AudioRecording;
use Laravel\Ai\Transcription;

/**
 * Real speech-to-text via the Laravel AI SDK. Which provider (OpenAI, ElevenLabs,
 * Mistral, Gemini) is used is decided by config/ai.php's `default_for_transcription`,
 * not here — this driver only shapes our own request/response boundary.
 */
final class LaravelAiAudioTranscriber implements TranscribesAudio
{
    public function transcribe(AudioRecording $recording): string
    {
        return (string) Transcription::fromStorage($recording->path, $recording->disk)->generate();
    }
}
