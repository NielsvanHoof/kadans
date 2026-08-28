<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Transcribe;

use App\AudioProcessing\Data\TranscriptionResult;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * Maps Amazon Transcribe JSON (with speaker labels) into our TranscriptionResult shape.
 */
final class TranscribeTranscriptMapper
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): TranscriptionResult
    {
        $results = $payload['results'] ?? null;

        if (! is_array($results)) {
            return new TranscriptionResult(text: '', segments: []);
        }

        $transcript = data_get($results, 'transcripts.0.transcript');
        $text = is_string($transcript) ? $transcript : '';

        $segments = $this->mapSpeakerSegments($results);

        // Speaker diarization is optional; fall back to timed word items so
        // the UI can still highlight progress from real start/end times.
        if ($segments === []) {
            $segments = $this->mapTimedItemSegments($results);
        }

        if ($segments === [] && $text !== '') {
            $segments = [[
                'text' => $text,
                'speaker' => 'Speaker 1',
                'start_seconds' => 0.0,
                'end_seconds' => 0.0,
            ]];
        }

        return new TranscriptionResult(text: $text, segments: $segments);
    }

    /**
     * @param  array<string, mixed>  $results
     * @return list<array{text: string, speaker: string, start_seconds: float, end_seconds: float}>
     */
    private function mapSpeakerSegments(array $results): array
    {
        $speakerLabels = $results['speaker_labels'] ?? null;

        if (! is_array($speakerLabels)) {
            return [];
        }

        $rawSegments = $speakerLabels['segments'] ?? null;

        if (! is_array($rawSegments)) {
            return [];
        }

        $itemsById = $this->indexItemsById($results['items'] ?? null);
        $segments = [];
        /** @var array<string, string> $speakerMap */
        $speakerMap = [];
        $nextSpeaker = 1;

        foreach ($rawSegments as $rawSegment) {
            if (! is_array($rawSegment)) {
                continue;
            }

            $label = $rawSegment['speaker_label'] ?? null;
            if (! is_string($label)) {
                continue;
            }
            if ($label === '') {
                continue;
            }

            if (! isset($speakerMap[$label])) {
                $speakerMap[$label] = 'Speaker '.$nextSpeaker;
                $nextSpeaker++;
            }

            $text = $this->segmentText($rawSegment, $itemsById);

            if ($text === '') {
                continue;
            }

            $segments[] = [
                'text' => $text,
                'speaker' => $speakerMap[$label],
                'start_seconds' => $this->toFloat($rawSegment['start_time'] ?? 0),
                'end_seconds' => $this->toFloat($rawSegment['end_time'] ?? 0),
            ];
        }

        return $segments;
    }

    /**
     * Build sentence-sized segments from word timings when speaker labels are missing.
     *
     * @param  array<string, mixed>  $results
     * @return list<array{text: string, speaker: string, start_seconds: float, end_seconds: float}>
     */
    private function mapTimedItemSegments(array $results): array
    {
        $items = $results['items'] ?? null;

        if (! is_array($items) || $items === []) {
            return [];
        }

        $segments = [];
        $parts = [];
        $sentenceStart = null;
        $sentenceEnd = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $content = $this->itemContent($item);

            if ($content === null) {
                continue;
            }

            $type = $item['type'] ?? 'pronunciation';

            if ($type === 'punctuation') {
                $parts[] = $content;
                if (! in_array($content, ['.', '!', '?'], true)) {
                    continue;
                }

                $text = mb_trim(implode('', $parts));

                if ($text !== '') {
                    $segments[] = [
                        'text' => $text,
                        'speaker' => 'Speaker 1',
                        'start_seconds' => $sentenceStart ?? 0.0,
                        'end_seconds' => $sentenceEnd,
                    ];
                }

                $parts = [];
                $sentenceStart = null;

                continue;
            }

            $start = $this->toFloat($item['start_time'] ?? 0);
            $end = $this->toFloat($item['end_time'] ?? 0);

            $sentenceStart ??= $start;

            $sentenceEnd = max($sentenceEnd, $end);

            if ($parts !== []) {
                $parts[] = ' ';
            }

            $parts[] = $content;
        }

        $remaining = mb_trim(implode('', $parts));

        if ($remaining !== '') {
            $segments[] = [
                'text' => $remaining,
                'speaker' => 'Speaker 1',
                'start_seconds' => $sentenceStart ?? 0.0,
                'end_seconds' => $sentenceEnd,
            ];
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemContent(array $item): ?string
    {
        $content = data_get($item, 'alternatives.0.content');

        if (! is_string($content) || $content === '') {
            return null;
        }

        return $content;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexItemsById(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $indexed = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;

            if (is_string($id) || is_numeric($id)) {
                $indexed[(string) $id] = $item;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $rawSegment
     * @param  array<string, array<string, mixed>>  $itemsById
     */
    private function segmentText(array $rawSegment, array $itemsById): string
    {
        $segmentItems = $rawSegment['items'] ?? null;

        if (! is_array($segmentItems) || $segmentItems === []) {
            return '';
        }

        $parts = [];

        foreach ($segmentItems as $segmentItem) {
            if (! is_array($segmentItem)) {
                continue;
            }

            $itemId = $segmentItem['item_id'] ?? null;

            if (! is_string($itemId) && ! is_numeric($itemId)) {
                continue;
            }

            $item = $itemsById[(string) $itemId] ?? null;

            if (! is_array($item)) {
                continue;
            }

            $content = $this->itemContent($item);

            if ($content === null) {
                continue;
            }

            $type = $item['type'] ?? 'pronunciation';

            if ($type === 'punctuation') {
                $parts[] = $content;

                continue;
            }

            if ($parts !== []) {
                $parts[] = ' ';
            }

            $parts[] = $content;
        }

        return mb_trim(implode('', $parts));
    }

    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return round((float) $value, 3);
        }

        return 0.0;
    }
}
