<?php

declare(strict_types=1);

use App\AudioProcessing\Data\AudioChunk;
use App\AudioProcessing\Data\TranscriptionResult;
use App\AudioProcessing\Services\Audio\TranscriptionResultMerger;

test('merger offsets timestamps and remaps speakers across chunks', function (): void {
    $merger = new TranscriptionResultMerger;

    $result = $merger->merge([
        [
            'chunk' => new AudioChunk(path: '/tmp/a.mp3', startSeconds: 0.0, durationSeconds: 90.0, index: 0),
            'result' => new TranscriptionResult(
                text: 'Hello there.',
                segments: [
                    ['text' => 'Hello there.', 'speaker' => 'A', 'start_seconds' => 1.0, 'end_seconds' => 2.5],
                ],
            ),
        ],
        [
            'chunk' => new AudioChunk(path: '/tmp/b.mp3', startSeconds: 90.0, durationSeconds: 90.0, index: 1),
            'result' => new TranscriptionResult(
                text: 'How are you?',
                segments: [
                    // Same local label "A" must become a new global speaker — we do not
                    // assume voice continuity across chunk boundaries.
                    ['text' => 'How are you?', 'speaker' => 'A', 'start_seconds' => 0.5, 'end_seconds' => 2.0],
                    ['text' => 'Fine.', 'speaker' => 'B', 'start_seconds' => 2.1, 'end_seconds' => 3.0],
                ],
            ),
        ],
    ]);

    expect($result->text)->toBe('Hello there. How are you?')
        ->and($result->segments)->toBe([
            ['text' => 'Hello there.', 'speaker' => 'Speaker 1', 'start_seconds' => 1.0, 'end_seconds' => 2.5],
            ['text' => 'How are you?', 'speaker' => 'Speaker 2', 'start_seconds' => 90.5, 'end_seconds' => 92.0],
            ['text' => 'Fine.', 'speaker' => 'Speaker 3', 'start_seconds' => 92.1, 'end_seconds' => 93.0],
        ]);
});

test('merger skips blank chunk text but keeps segments', function (): void {
    $merger = new TranscriptionResultMerger;

    $result = $merger->merge([
        [
            'chunk' => new AudioChunk(path: '/tmp/a.mp3', startSeconds: 0.0, durationSeconds: 10.0, index: 0),
            'result' => new TranscriptionResult(text: '  ', segments: []),
        ],
        [
            'chunk' => new AudioChunk(path: '/tmp/b.mp3', startSeconds: 10.0, durationSeconds: 10.0, index: 1),
            'result' => new TranscriptionResult(
                text: 'Later.',
                segments: [
                    ['text' => 'Later.', 'speaker' => 'A', 'start_seconds' => 0.0, 'end_seconds' => 1.0],
                ],
            ),
        ],
    ]);

    expect($result->text)->toBe('Later.')
        ->and($result->segments)->toHaveCount(1)
        ->and($result->segments[0]['start_seconds'])->toBe(10.0);
});
