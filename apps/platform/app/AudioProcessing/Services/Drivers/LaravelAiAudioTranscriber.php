<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Drivers;

use App\AudioProcessing\Contracts\TranscribesAudio;
use App\AudioProcessing\Data\TranscriptionResult;
use App\Dossiers\Models\AudioRecording;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Transcription;

/**
 * Real speech-to-text via the Laravel AI SDK. Which provider (OpenAI, ElevenLabs,
 * Mistral, Gemini) is used is decided by config/ai.php's `default_for_transcription`,
 * not here — this driver only shapes our own request/response boundary.
 */
final class LaravelAiAudioTranscriber implements TranscribesAudio
{
    public function transcribe(AudioRecording $recording): TranscriptionResult
    {
        $response = Transcription::fromStorage($recording->path, $recording->disk)
            ->diarize()
            // OpenAI's diarized transcription endpoint 400s without this — it's not implied by ->diarize().
            ->withProviderOptions(['chunking_strategy' => 'auto'])
            // The SDK's 30s default is too short for real recordings — keep this under
            // the job's own $timeout (see ProcessAudioRecordingJob) with headroom to spare.
            ->timeout(120)
            ->generate();

        return new TranscriptionResult(
            text: $response->text,
            segments: $response->segments
                ->map(fn (TranscriptionSegment $segment): array => $segment->toArray())
                ->all(),
        );
    }
}
