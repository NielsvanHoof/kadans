<?php

declare(strict_types=1);

use App\AudioProcessing\Services\Transcribe\TranscribeTranscriptMapper;
use App\AudioProcessing\Services\Transcribe\TranscribeTranscriptProjection;
use Tests\TestCase;

covers(TranscribeTranscriptProjection::class);

pest()
    ->extend(TestCase::class);

/**
 * An Amazon Transcribe payload shaped like the real thing: diarized segments,
 * per-word items carrying a confidence the mapper never reads, and the result
 * sections and job metadata it ignores entirely.
 *
 * @return array<string, mixed>
 */
function transcribePayloadWithProviderNoise(): array
{
    $word = static fn (string $id, string $content, string $start, string $end, string $type = 'pronunciation'): array => [
        'id' => $id,
        'type' => $type,
        'start_time' => $start,
        'end_time' => $end,
        'alternatives' => [
            ['confidence' => '0.9987', 'content' => $content],
        ],
    ];

    return [
        'jobName' => 'audio-42-testjob',
        'accountId' => '123456789012',
        'status' => 'COMPLETED',
        'results' => [
            'transcripts' => [
                ['transcript' => 'Goedemiddag. Het gaat om de jaarrekening.'],
            ],
            'speaker_labels' => [
                'channel_label' => 'ch_0',
                'speakers' => 2,
                'segments' => [
                    [
                        'start_time' => '0.0',
                        'end_time' => '1.5',
                        'speaker_label' => 'spk_0',
                        'items' => [
                            ['start_time' => '0.0', 'end_time' => '1.0', 'speaker_label' => 'spk_0', 'item_id' => '0'],
                            ['start_time' => '1.0', 'end_time' => '1.5', 'speaker_label' => 'spk_0', 'item_id' => '1'],
                        ],
                    ],
                    [
                        'start_time' => '1.6',
                        'end_time' => '4.0',
                        'speaker_label' => 'spk_1',
                        'items' => [
                            ['start_time' => '1.6', 'end_time' => '4.0', 'speaker_label' => 'spk_1', 'item_id' => '2'],
                        ],
                    ],
                ],
            ],
            'audio_segments' => [
                ['id' => 0, 'transcript' => 'Goedemiddag.', 'items' => [0, 1]],
            ],
            'language_code' => 'nl-NL',
            'items' => [
                $word('0', 'Goedemiddag', '0.0', '1.0'),
                ['id' => '1', 'type' => 'punctuation', 'alternatives' => [['confidence' => '0.0', 'content' => '.']]],
                $word('2', 'Jaarrekening', '1.6', '4.0'),
            ],
        ],
    ];
}

test('projecting a transcript does not change what the mapper produces', function (): void {
    $mapper = resolve(TranscribeTranscriptMapper::class);
    $payload = transcribePayloadWithProviderNoise();

    $projected = resolve(TranscribeTranscriptProjection::class)->project($payload);

    $fromProjected = $mapper->map($projected);
    $fromRaw = $mapper->map($payload);

    expect($fromProjected->text)->toBe($fromRaw->text)
        ->and($fromProjected->segments)->toBe($fromRaw->segments);
});

test('projection drops per-word confidence, unread result sections and job metadata', function (): void {
    $projected = resolve(TranscribeTranscriptProjection::class)
        ->project(transcribePayloadWithProviderNoise());

    expect($projected)->toHaveKeys(['results'])
        ->and($projected)->not->toHaveKey('jobName')
        ->and($projected)->not->toHaveKey('accountId')
        ->and($projected)->not->toHaveKey('status')
        ->and($projected['results'])->not->toHaveKey('audio_segments')
        ->and($projected['results'])->not->toHaveKey('language_code')
        ->and($projected['results']['speaker_labels'])->not->toHaveKey('speakers');

    // The bulk of the payload is one entry per word; confidence is on every one.
    foreach ($projected['results']['items'] as $item) {
        expect($item['alternatives'][0])->not->toHaveKey('confidence')
            ->and($item['alternatives'][0])->toHaveKey('content');
    }

    // Segment items are referenced by id only.
    foreach ($projected['results']['speaker_labels']['segments'] as $segment) {
        expect($segment)->toHaveKeys(['speaker_label', 'start_time', 'end_time', 'items']);

        foreach ($segment['items'] as $segmentItem) {
            expect($segmentItem)->toBe(['item_id' => $segmentItem['item_id']]);
        }
    }
});

test('a payload without diarization stays without it rather than gaining an empty section', function (): void {
    // The mapper falls back to timed word items when speaker_labels is absent;
    // an empty section would read as "diarization ran and found nobody".
    $payload = transcribePayloadWithProviderNoise();
    unset($payload['results']['speaker_labels']);

    $projected = resolve(TranscribeTranscriptProjection::class)->project($payload);

    expect($projected['results'])->not->toHaveKey('speaker_labels');

    $mapper = resolve(TranscribeTranscriptMapper::class);

    $fromProjected = $mapper->map($projected);
    $fromRaw = $mapper->map($payload);

    expect($fromProjected->text)->toBe($fromRaw->text)
        ->and($fromProjected->segments)->toBe($fromRaw->segments);
});
