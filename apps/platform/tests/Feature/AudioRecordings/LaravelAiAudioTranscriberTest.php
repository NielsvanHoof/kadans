<?php

declare(strict_types=1);

use App\AudioProcessing\Services\Drivers\LaravelAiAudioTranscriber;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Support\Collection;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;

test('driver requests diarization and maps segments into a transcription result', function (): void {
    Transcription::fake([
        new TranscriptionResponse(
            'Hello there. How are you?',
            new Collection([
                new TranscriptionSegment('Hello there.', 'Speaker 1', 0.0, 1.2),
                new TranscriptionSegment('How are you?', 'Speaker 2', 1.5, 2.8),
            ]),
            new Usage,
            new Meta('openai', 'whisper-1'),
        ),
    ]);

    $recording = new AudioRecording([
        'disk' => 'local',
        'path' => 'audio/call.mp3',
        'original_filename' => 'call.mp3',
    ]);

    $result = resolve(LaravelAiAudioTranscriber::class)->transcribe($recording);

    expect($result->text)->toBe('Hello there. How are you?')
        ->and($result->segments)->toBe([
            ['text' => 'Hello there.', 'speaker' => 'Speaker 1', 'start_seconds' => 0.0, 'end_seconds' => 1.2],
            ['text' => 'How are you?', 'speaker' => 'Speaker 2', 'start_seconds' => 1.5, 'end_seconds' => 2.8],
        ]);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->isDiarized()
        // OpenAI's diarized transcription endpoint 400s without this provider option.
        && ($prompt->providerOptions['chunking_strategy'] ?? null) === 'auto');
});
