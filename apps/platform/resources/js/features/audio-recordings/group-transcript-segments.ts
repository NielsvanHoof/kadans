import type { TranscriptSegment } from '@/features/audio-recordings/types';

const PAUSE_THRESHOLD_SECONDS = 2;

export type TranscriptParagraph = {
    speaker: string;
    startSeconds: number;
    text: string;
};

/**
 * Group flat transcript segments into readable paragraphs. A new paragraph
 * starts on a speaker change or after a pause longer than the threshold;
 * otherwise the segment's text is appended to the current paragraph. This is
 * what turns a single-speaker monologue into naturally-paced paragraphs, not
 * just multi-speaker calls.
 */
export function groupTranscriptSegments(
    segments: TranscriptSegment[],
): TranscriptParagraph[] {
    const paragraphs: TranscriptParagraph[] = [];
    let previousSegment: TranscriptSegment | null = null;

    for (const segment of segments) {
        const gap = previousSegment
            ? segment.start_seconds - previousSegment.end_seconds
            : Infinity;
        const startsNewParagraph =
            previousSegment === null ||
            previousSegment.speaker !== segment.speaker ||
            gap > PAUSE_THRESHOLD_SECONDS;

        if (startsNewParagraph) {
            paragraphs.push({
                speaker: segment.speaker,
                startSeconds: segment.start_seconds,
                text: segment.text,
            });
        } else {
            const current = paragraphs[paragraphs.length - 1];
            current.text = `${current.text} ${segment.text}`;
        }

        previousSegment = segment;
    }

    return paragraphs;
}
