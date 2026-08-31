import { describe, expect, it } from 'vite-plus/test';
import { buildTranscriptSentences } from '@/features/audio-recordings/build-sentences/build-transcript-sentences';
import type { TranscriptSegment } from '@/features/audio-recordings/types';

function segment(
    overrides: Partial<TranscriptSegment> = {},
): TranscriptSegment {
    return {
        text: 'Hello world.',
        speaker: 'Speaker 1',
        start_seconds: 0,
        end_seconds: 2,
        ...overrides,
    };
}

describe('buildTranscriptSentences', () => {
    it('splits segment text into sentences and preserves punctuation', () => {
        const result = buildTranscriptSentences([
            segment({
                text: 'Hello world. How are you? Great!',
                start_seconds: 10,
                end_seconds: 16,
            }),
        ]);

        expect(result.map((item) => item.text)).toEqual([
            'Hello world.',
            'How are you?',
            'Great!',
        ]);
    });

    it('distributes sentence timings within a segment', () => {
        const result = buildTranscriptSentences([
            segment({
                text: 'One. Two.',
                start_seconds: 0,
                end_seconds: 8,
            }),
        ]);

        expect(result).toHaveLength(2);
        expect(result[0].startSeconds).toBe(0);
        expect(result[0].endSeconds).toBeGreaterThan(0);
        expect(result[1].startSeconds).toBeGreaterThan(result[0].startSeconds);
        expect(result[1].endSeconds).toBe(8);
    });

    it('handles segments with no sentence punctuation', () => {
        const result = buildTranscriptSentences([
            segment({
                text: 'No punctuation text',
                start_seconds: 2,
                end_seconds: 5,
            }),
        ]);

        expect(result).toEqual([
            {
                id: '0-0',
                speaker: 'Speaker 1',
                text: 'No punctuation text',
                startSeconds: 2,
                endSeconds: 5,
            },
        ]);
    });
});
