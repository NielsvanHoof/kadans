import type { TranscriptSegment } from '@/features/audio-recordings/types';

export type TranscriptSentence = {
    id: string;
    speaker: string;
    text: string;
    startSeconds: number;
    endSeconds: number;
};

const SENTENCE_END_PATTERN = /[^.!?]+[.!?]+|[^.!?]+$/g;

type TextChunk = { text: string };

function splitSentenceChunks(text: string): TextChunk[] {
    const chunks: TextChunk[] = [];
    const matches = text.matchAll(SENTENCE_END_PATTERN);

    for (const match of matches) {
        const value = match[0]?.trim();

        if (!value) {
            continue;
        }

        chunks.push({ text: value });
    }

    return chunks;
}

function roundSeconds(value: number): number {
    return Number(value.toFixed(3));
}

/**
 * Build timed sentences from transcript segments.
 * Timing comes from segment start/end; multi-sentence segments are split
 * proportionally by character length inside that segment.
 */
export function buildTranscriptSentences(
    segments: TranscriptSegment[],
): TranscriptSentence[] {
    const sentences: TranscriptSentence[] = [];

    for (const [segmentIndex, segment] of segments.entries()) {
        const chunks = splitSentenceChunks(segment.text);

        if (chunks.length === 0) {
            continue;
        }

        const segmentDuration = Math.max(
            segment.end_seconds - segment.start_seconds,
            0,
        );
        const totalChars = chunks.reduce(
            (sum, chunk) => sum + chunk.text.length,
            0,
        );

        for (const [chunkIndex, chunk] of chunks.entries()) {
            let startSeconds = segment.start_seconds;
            let endSeconds = segment.end_seconds;

            if (segmentDuration > 0 && totalChars > 0) {
                const priorChars = chunks
                    .slice(0, chunkIndex)
                    .reduce((sum, item) => sum + item.text.length, 0);
                const nextChars = priorChars + chunk.text.length;
                startSeconds =
                    segment.start_seconds +
                    (priorChars / totalChars) * segmentDuration;
                endSeconds =
                    segment.start_seconds +
                    (nextChars / totalChars) * segmentDuration;
            }

            sentences.push({
                id: `${segmentIndex}-${chunkIndex}`,
                speaker: segment.speaker,
                text: chunk.text,
                startSeconds: roundSeconds(startSeconds),
                endSeconds: roundSeconds(Math.max(endSeconds, startSeconds)),
            });
        }
    }

    return sentences;
}
