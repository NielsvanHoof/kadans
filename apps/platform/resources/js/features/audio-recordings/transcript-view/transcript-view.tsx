import { buildTranscriptSentences } from '@/features/audio-recordings/build-sentences/build-transcript-sentences';
import type { TranscriptSegment } from '@/features/audio-recordings/types';

type TranscriptViewProps = {
    transcript: string | null;
    segments: TranscriptSegment[] | null;
    currentTimeSeconds: number;
};

/**
 * Renders a live, sentence-highlighted transcript synced to audio playback.
 * Falls back to the raw transcript text when segments are unavailable.
 */
export default function TranscriptView({
    transcript,
    segments,
    currentTimeSeconds,
}: TranscriptViewProps) {
    if (segments && segments.length > 0) {
        const sentences = buildTranscriptSentences(segments);

        return (
            <div className="rounded-md border bg-muted/20 p-4">
                <p className="mb-3 text-xs text-muted-foreground">
                    Live transcript progress
                </p>
                <p className="text-base leading-relaxed">
                    {sentences.map((sentence, index) => {
                        const isLast = index === sentences.length - 1;
                        const isActive =
                            currentTimeSeconds >= sentence.startSeconds &&
                            (isLast
                                ? currentTimeSeconds <= sentence.endSeconds
                                : currentTimeSeconds < sentence.endSeconds);

                        return (
                            <span
                                key={sentence.id}
                                className={`rounded px-1 py-0.5 transition ${
                                    isActive
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-foreground/60'
                                }`}
                            >
                                {sentence.text}{' '}
                            </span>
                        );
                    })}
                </p>
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
