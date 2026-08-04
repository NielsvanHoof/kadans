import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TranscriptView from '@/features/audio-recordings/transcript-view/transcript-view';
import type { TranscriptSegment } from '@/features/audio-recordings/types';

const segments: TranscriptSegment[] = [
    {
        text: 'Hello world. How are you?',
        speaker: 'Speaker 1',
        start_seconds: 0,
        end_seconds: 4,
    },
];

describe('TranscriptView', () => {
    it('highlights the active sentence for the current playback time', () => {
        render(
            <TranscriptView
                transcript="Hello world. How are you?"
                segments={segments}
                currentTimeSeconds={0.5}
            />,
        );

        const activeSentence = screen.getByText('Hello world.');
        const inactiveSentence = screen.getByText('How are you?');

        expect(activeSentence.className).toContain('bg-primary');
        expect(inactiveSentence.className).not.toContain('bg-primary');
    });
});
