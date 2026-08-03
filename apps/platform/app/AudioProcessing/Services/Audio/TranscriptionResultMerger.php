<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Audio;

use App\AudioProcessing\Data\AudioChunk;
use App\AudioProcessing\Data\TranscriptionResult;

/**
 * Stitches per-chunk transcription results into one timeline.
 *
 * Timestamps are offset by each chunk's start. Speaker labels are remapped to
 * unique global names per chunk — we do not try to match the same voice across
 * chunk boundaries (that needs speaker embeddings).
 */
final class TranscriptionResultMerger
{
    /**
     * @param  list<array{chunk: AudioChunk, result: TranscriptionResult}>  $parts
     */
    public function merge(array $parts): TranscriptionResult
    {
        $textParts = [];
        $segments = [];
        $nextSpeakerNumber = 1;

        foreach ($parts as $part) {
            $chunk = $part['chunk'];
            $result = $part['result'];

            if (mb_trim($result->text) !== '') {
                $textParts[] = mb_trim($result->text);
            }

            // Map this chunk's local speaker labels onto fresh global ones.
            /** @var array<string, string> $speakerMap */
            $speakerMap = [];

            foreach ($result->segments as $segment) {
                $localSpeaker = $segment['speaker'];

                if (! isset($speakerMap[$localSpeaker])) {
                    $speakerMap[$localSpeaker] = 'Speaker '.$nextSpeakerNumber;
                    $nextSpeakerNumber++;
                }

                $segments[] = [
                    'text' => $segment['text'],
                    'speaker' => $speakerMap[$localSpeaker],
                    'start_seconds' => round($segment['start_seconds'] + $chunk->startSeconds, 3),
                    'end_seconds' => round($segment['end_seconds'] + $chunk->startSeconds, 3),
                ];
            }
        }

        return new TranscriptionResult(
            text: implode(' ', $textParts),
            segments: $segments,
        );
    }
}
