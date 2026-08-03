import { groupTranscriptSegments } from '@/features/audio-recordings/group-transcript-segments';
import type { TranscriptSegment } from '@/features/audio-recordings/types';
import { formatDuration } from '@/lib/format-duration';

type TranscriptViewProps = {
    transcript: string | null;
    segments: TranscriptSegment[] | null;
};

/**
 * Renders a transcript as timestamped, speaker-grouped paragraphs when
 * segments are available. Falls back to the raw transcript text for
 * recordings transcribed before segment capture was added.
 */
export default function TranscriptView({
    transcript,
    segments,
}: TranscriptViewProps) {
    if (segments && segments.length > 0) {
        const paragraphs = groupTranscriptSegments(segments);
        const hasMultipleSpeakers =
            new Set(segments.map((segment) => segment.speaker)).size > 1;

        return (
            <div className="flex flex-col gap-4">
                {paragraphs.map((paragraph) => (
                    <div
                        key={`${paragraph.speaker}-${paragraph.startSeconds}`}
                        className="flex flex-col gap-1"
                    >
                        <p className="font-data text-xs text-muted-foreground">
                            {hasMultipleSpeakers && (
                                <span className="font-medium">
                                    {paragraph.speaker} ·{' '}
                                </span>
                            )}
                            {formatDuration(paragraph.startSeconds)}
                        </p>
                        <p className="text-base leading-relaxed whitespace-pre-wrap">
                            {paragraph.text}
                        </p>
                    </div>
                ))}
            </div>
        );
    }

    if (!transcript) {
        return null;
    }

    return (
        <p className="text-base leading-relaxed whitespace-pre-wrap">
            {transcript}
        </p>
    );
}
