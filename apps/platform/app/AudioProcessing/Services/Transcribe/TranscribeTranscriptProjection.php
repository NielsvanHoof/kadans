<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Transcribe;

use function is_array;

/**
 * Narrows a decoded Amazon Transcribe payload to what TranscribeTranscriptMapper reads.
 *
 * The transcript JSON for an hour of audio carries one entry per word, each
 * with its own `alternatives` list holding a confidence score the mapper never
 * looks at, plus whole result sections it ignores (`audio_segments`,
 * `channel_labels`, language identification) and job metadata. Decoded, that is
 * several PHP hashtables per spoken word.
 *
 * A flat `array_intersect_key` mask cannot express this the way the Textract
 * block projection can, because what to keep is nested three levels deep and
 * differs per section — hence a rebuild, and hence its own class.
 *
 * Absent keys are simply omitted rather than defaulted: the mapper guards every
 * read with an `is_array()` / `is_string()` check already, so a payload missing
 * `speaker_labels` must reach it missing, not as an empty array that reads as
 * "diarization ran and found nobody".
 */
final class TranscribeTranscriptProjection
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function project(array $payload): array
    {
        $results = $payload['results'] ?? null;

        if (! is_array($results)) {
            return $payload;
        }

        $projected = [];

        if (is_array($results['transcripts'] ?? null)) {
            $projected['transcripts'] = $this->projectTranscripts($results['transcripts']);
        }

        if (is_array($results['speaker_labels'] ?? null)) {
            $projected['speaker_labels'] = $this->projectSpeakerLabels($results['speaker_labels']);
        }

        if (is_array($results['items'] ?? null)) {
            $projected['items'] = $this->projectItems($results['items']);
        }

        return ['results' => $projected];
    }

    /**
     * Only `transcripts.0.transcript` is read, but the list is kept as a list so
     * the mapper's `data_get('transcripts.0.transcript')` still resolves.
     *
     * @param  array<mixed>  $transcripts
     * @return list<array<string, mixed>>
     */
    private function projectTranscripts(array $transcripts): array
    {
        $projected = [];

        foreach ($transcripts as $transcript) {
            if (! is_array($transcript)) {
                continue;
            }

            $projected[] = array_intersect_key($transcript, ['transcript' => true]);
        }

        return $projected;
    }

    /**
     * @param  array<string, mixed>  $speakerLabels
     * @return array<string, mixed>
     */
    private function projectSpeakerLabels(array $speakerLabels): array
    {
        $rawSegments = $speakerLabels['segments'] ?? null;

        if (! is_array($rawSegments)) {
            return [];
        }

        $segments = [];

        foreach ($rawSegments as $rawSegment) {
            if (! is_array($rawSegment)) {
                continue;
            }

            $segment = array_intersect_key($rawSegment, [
                'speaker_label' => true,
                'start_time' => true,
                'end_time' => true,
            ]);

            // Segment items are referenced by id only; the word content itself
            // is resolved out of `results.items`.
            if (is_array($rawSegment['items'] ?? null)) {
                $segment['items'] = $this->projectSegmentItems($rawSegment['items']);
            }

            $segments[] = $segment;
        }

        return ['segments' => $segments];
    }

    /**
     * @param  array<mixed>  $segmentItems
     * @return list<array<string, mixed>>
     */
    private function projectSegmentItems(array $segmentItems): array
    {
        $projected = [];

        foreach ($segmentItems as $segmentItem) {
            if (! is_array($segmentItem)) {
                continue;
            }

            $projected[] = array_intersect_key($segmentItem, ['item_id' => true]);
        }

        return $projected;
    }

    /**
     * The bulk of the payload: one entry per word. `alternatives` is narrowed to
     * the first entry's `content`, which is all `itemContent()` reads.
     *
     * @param  array<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function projectItems(array $items): array
    {
        $projected = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $entry = array_intersect_key($item, [
                'id' => true,
                'type' => true,
                'start_time' => true,
                'end_time' => true,
            ]);

            $alternatives = $item['alternatives'] ?? null;

            if (is_array($alternatives) && is_array($alternatives[0] ?? null)) {
                $entry['alternatives'] = [
                    array_intersect_key($alternatives[0], ['content' => true]),
                ];
            }

            $projected[] = $entry;
        }

        return $projected;
    }
}
