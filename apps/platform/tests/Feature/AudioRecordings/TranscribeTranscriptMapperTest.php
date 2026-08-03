<?php

declare(strict_types=1);

use App\AudioProcessing\Services\Transcribe\TranscribeTranscriptMapper;

test('mapper builds transcript text and speaker segments from Transcribe JSON', function (): void {
    $payload = [
        'results' => [
            'transcripts' => [
                ['transcript' => 'Hello there. How are you?'],
            ],
            'items' => [
                [
                    'id' => '0',
                    'type' => 'pronunciation',
                    'alternatives' => [['content' => 'Hello']],
                ],
                [
                    'id' => '1',
                    'type' => 'pronunciation',
                    'alternatives' => [['content' => 'there']],
                ],
                [
                    'id' => '2',
                    'type' => 'punctuation',
                    'alternatives' => [['content' => '.']],
                ],
                [
                    'id' => '3',
                    'type' => 'pronunciation',
                    'alternatives' => [['content' => 'How']],
                ],
                [
                    'id' => '4',
                    'type' => 'pronunciation',
                    'alternatives' => [['content' => 'are']],
                ],
                [
                    'id' => '5',
                    'type' => 'pronunciation',
                    'alternatives' => [['content' => 'you']],
                ],
                [
                    'id' => '6',
                    'type' => 'punctuation',
                    'alternatives' => [['content' => '?']],
                ],
            ],
            'speaker_labels' => [
                'speakers' => 2,
                'segments' => [
                    [
                        'start_time' => '0.0',
                        'end_time' => '1.2',
                        'speaker_label' => 'spk_0',
                        'items' => [
                            ['item_id' => '0'],
                            ['item_id' => '1'],
                            ['item_id' => '2'],
                        ],
                    ],
                    [
                        'start_time' => '1.5',
                        'end_time' => '2.8',
                        'speaker_label' => 'spk_1',
                        'items' => [
                            ['item_id' => '3'],
                            ['item_id' => '4'],
                            ['item_id' => '5'],
                            ['item_id' => '6'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $result = (new TranscribeTranscriptMapper)->map($payload);

    expect($result->text)->toBe('Hello there. How are you?')
        ->and($result->segments)->toBe([
            ['text' => 'Hello there.', 'speaker' => 'Speaker 1', 'start_seconds' => 0.0, 'end_seconds' => 1.2],
            ['text' => 'How are you?', 'speaker' => 'Speaker 2', 'start_seconds' => 1.5, 'end_seconds' => 2.8],
        ]);
});

test('mapper falls back to a single segment when speaker labels are missing', function (): void {
    $result = (new TranscribeTranscriptMapper)->map([
        'results' => [
            'transcripts' => [
                ['transcript' => 'Just text.'],
            ],
        ],
    ]);

    expect($result->text)->toBe('Just text.')
        ->and($result->segments)->toBe([
            ['text' => 'Just text.', 'speaker' => 'Speaker 1', 'start_seconds' => 0.0, 'end_seconds' => 0.0],
        ]);
});
