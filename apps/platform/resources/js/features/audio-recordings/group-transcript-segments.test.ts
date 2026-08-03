import { describe, expect, it } from 'vitest';
import { groupTranscriptSegments } from '@/features/audio-recordings/group-transcript-segments';
import type { TranscriptSegment } from '@/features/audio-recordings/types';

function segment(
    overrides: Partial<TranscriptSegment> = {},
): TranscriptSegment {
    return {
        text: 'Hello.',
        speaker: 'Speaker 1',
        start_seconds: 0,
        end_seconds: 1,
        ...overrides,
    };
}

describe('groupTranscriptSegments', () => {
    it('returns an empty list for no segments', () => {
        expect(groupTranscriptSegments([])).toEqual([]);
    });

    it('merges consecutive same-speaker segments spoken without a pause', () => {
        const paragraphs = groupTranscriptSegments([
            segment({ text: 'Hello there.', start_seconds: 0, end_seconds: 1 }),
            segment({
                text: 'How are you?',
                start_seconds: 1.2,
                end_seconds: 2,
            }),
        ]);

        expect(paragraphs).toEqual([
            {
                speaker: 'Speaker 1',
                startSeconds: 0,
                text: 'Hello there. How are you?',
            },
        ]);
    });

    it('starts a new paragraph after a long pause from the same speaker', () => {
        const paragraphs = groupTranscriptSegments([
            segment({
                text: 'First thought.',
                start_seconds: 0,
                end_seconds: 1,
            }),
            segment({
                text: 'Second thought.',
                start_seconds: 5,
                end_seconds: 6,
            }),
        ]);

        expect(paragraphs).toEqual([
            { speaker: 'Speaker 1', startSeconds: 0, text: 'First thought.' },
            { speaker: 'Speaker 1', startSeconds: 5, text: 'Second thought.' },
        ]);
    });

    it('starts a new paragraph on a speaker change, even without a pause', () => {
        const paragraphs = groupTranscriptSegments([
            segment({
                text: 'Hi.',
                speaker: 'Speaker 1',
                start_seconds: 0,
                end_seconds: 1,
            }),
            segment({
                text: 'Hi there.',
                speaker: 'Speaker 2',
                start_seconds: 1,
                end_seconds: 2,
            }),
        ]);

        expect(paragraphs).toEqual([
            { speaker: 'Speaker 1', startSeconds: 0, text: 'Hi.' },
            { speaker: 'Speaker 2', startSeconds: 1, text: 'Hi there.' },
        ]);
    });
});
