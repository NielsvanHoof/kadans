<?php

declare(strict_types=1);

use App\AudioProcessing\Contracts\ChunksAudio;
use App\AudioProcessing\Data\AudioChunk;
use App\AudioProcessing\Services\Drivers\LaravelAiAudioTranscriber;
use App\Dossiers\Models\AudioRecording;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use Mockery\MockInterface;

test('driver requests diarization and maps segments into a transcription result', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('audio/call.mp3', 'fake-audio-bytes');

    // Bypass ffmpeg in this unit test — return the downloaded source as one chunk.
    $this->mock(ChunksAudio::class, function (MockInterface $mock): void {
        $mock->shouldReceive('chunk')
            ->once()
            ->andReturnUsing(fn (string $localPath): array => [
                new AudioChunk(
                    path: $localPath,
                    startSeconds: 0.0,
                    durationSeconds: 3.0,
                    index: 0,
                ),
            ]);
    });

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
    $recording->id = 1;
    $recording->tenant_id = 1;

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

test('driver stitches multiple chunk responses with time offsets', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('audio/long.mp3', 'fake-audio-bytes');

    $this->mock(ChunksAudio::class, function (MockInterface $mock): void {
        $mock->shouldReceive('chunk')
            ->once()
            ->andReturnUsing(fn (string $localPath): array => [
                new AudioChunk(path: $localPath, startSeconds: 0.0, durationSeconds: 90.0, index: 0),
                new AudioChunk(path: $localPath, startSeconds: 90.0, durationSeconds: 90.0, index: 1),
            ]);
    });

    Transcription::fake([
        new TranscriptionResponse(
            'First half.',
            new Collection([
                new TranscriptionSegment('First half.', 'A', 0.0, 1.0),
            ]),
            new Usage,
            new Meta('openai', 'whisper-1'),
        ),
        new TranscriptionResponse(
            'Second half.',
            new Collection([
                new TranscriptionSegment('Second half.', 'A', 0.0, 1.5),
            ]),
            new Usage,
            new Meta('openai', 'whisper-1'),
        ),
    ]);

    $recording = new AudioRecording([
        'disk' => 'local',
        'path' => 'audio/long.mp3',
        'original_filename' => 'long.mp3',
    ]);
    $recording->id = 2;
    $recording->tenant_id = 1;

    $result = resolve(LaravelAiAudioTranscriber::class)->transcribe($recording);

    expect($result->text)->toBe('First half. Second half.')
        ->and($result->segments)->toBe([
            ['text' => 'First half.', 'speaker' => 'Speaker 1', 'start_seconds' => 0.0, 'end_seconds' => 1.0],
            // Second chunk's local "A" becomes Speaker 2 (no cross-chunk identity).
            ['text' => 'Second half.', 'speaker' => 'Speaker 2', 'start_seconds' => 90.0, 'end_seconds' => 91.5],
        ]);
});
