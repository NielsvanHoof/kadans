import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { RecordingShowTab } from '@/features/audio-recordings/recording-show-tab';
import type {
    AudioRecordingCalendarLink,
    AudioRecordingShowProps,
} from '@/features/audio-recordings/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/features/audio-recordings/calendar-attach-panel', () => ({
    default: () => <div data-testid="calendar-panel" />,
}));

vi.mock('@/features/audio-recordings/transcript-view/transcript-view', () => ({
    default: () => <div data-testid="transcript-view" />,
}));

const { default: RecordingShowPanels } =
    await import('@/features/audio-recordings/recording-show-panels');

type AudioRecordingDetail = AudioRecordingShowProps['audio_recording'];

function calendarLink(
    overrides: Partial<AudioRecordingCalendarLink> = {},
): AudioRecordingCalendarLink {
    return {
        id: 1,
        provider: 'google',
        event_id: 'evt-1',
        html_link: null,
        linked_at: '2026-08-31T10:00:00Z',
        ...overrides,
    };
}

function recording(
    overrides: Partial<AudioRecordingDetail> = {},
): AudioRecordingDetail {
    return {
        id: 1,
        original_filename: 'call.mp3',
        transcription_status: 'completed',
        transcription_error: null,
        transcript: 'Hello.',
        segments: null,
        calendar_links: [],
        ...overrides,
    } as AudioRecordingDetail;
}

const calendar: AudioRecordingShowProps['calendar'] = {
    provider: 'google',
    connection_status: 'ready',
    reconnect_url: null,
};

function renderPanels({
    tab = 'transcript',
    onSelectTab = vi.fn(),
    audioRecording = recording(),
}: {
    tab?: RecordingShowTab;
    onSelectTab?: (next: RecordingShowTab) => void;
    audioRecording?: AudioRecordingDetail;
} = {}) {
    return render(
        <RecordingShowPanels
            audioRecording={audioRecording}
            calendar={calendar}
            currentTimeSeconds={0}
            tab={tab}
            onSelectTab={onSelectTab}
        />,
    );
}

function tabNames(): string[] {
    return screen
        .getAllByRole('tab')
        .map((item) => item.textContent?.trim() ?? '');
}

describe('RecordingShowPanels', () => {
    it('renders both tabs and shows the transcript body by default', () => {
        renderPanels();

        expect(tabNames()).toEqual(['Transcript', 'Calendar']);
        expect(screen.getByTestId('transcript-view')).toBeInTheDocument();
        expect(screen.queryByTestId('calendar-panel')).not.toBeInTheDocument();
    });

    it('omits a Calendar count of zero', () => {
        renderPanels();

        expect(tabNames()).toContain('Calendar');
        expect(tabNames()).not.toContain('Calendar1');
    });

    it('counts attached meetings for the current provider only', () => {
        renderPanels({
            audioRecording: recording({
                calendar_links: [
                    calendarLink({ id: 1, event_id: 'a' }),
                    calendarLink({ id: 2, event_id: 'b' }),
                    calendarLink({
                        id: 3,
                        event_id: 'c',
                        provider: 'microsoft',
                    }),
                ],
            }),
        });

        expect(tabNames()).toContain('Calendar2');
    });

    it('keeps the Calendar tab while transcription is still running', () => {
        renderPanels({
            audioRecording: recording({
                transcription_status: 'processing',
                transcript: null,
            }),
        });

        expect(tabNames()).toContain('Calendar');
        expect(
            screen.getByText(
                'Transcription is still running. The transcript will appear automatically.',
            ),
        ).toBeInTheDocument();
    });

    it('shows the empty Calendar sentence until the transcript is ready', () => {
        renderPanels({
            tab: 'calendar',
            audioRecording: recording({
                transcription_status: 'processing',
                transcript: null,
            }),
        });

        expect(
            screen.getByText('Available when the transcript is ready.'),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('calendar-panel')).not.toBeInTheDocument();
    });

    it('mounts the Calendar panel only after that tab is selected', async () => {
        const user = userEvent.setup();

        function Harness() {
            const [tab, setTab] = useState<RecordingShowTab>('transcript');

            return (
                <RecordingShowPanels
                    audioRecording={recording()}
                    calendar={calendar}
                    currentTimeSeconds={0}
                    tab={tab}
                    onSelectTab={setTab}
                />
            );
        }

        render(<Harness />);

        expect(screen.getByTestId('transcript-view')).toBeInTheDocument();
        expect(screen.queryByTestId('calendar-panel')).not.toBeInTheDocument();

        await user.click(screen.getByRole('tab', { name: 'Calendar' }));

        expect(screen.getByTestId('calendar-panel')).toBeInTheDocument();
        expect(screen.queryByTestId('transcript-view')).not.toBeInTheDocument();
    });

    it('shows the Calendar panel when the Calendar tab is selected', () => {
        renderPanels({ tab: 'calendar' });

        expect(screen.getByTestId('calendar-panel')).toBeInTheDocument();
        expect(screen.queryByTestId('transcript-view')).not.toBeInTheDocument();
    });
});
