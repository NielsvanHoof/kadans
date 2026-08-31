import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { AudioRecording } from '@/features/audio-recordings/types';
import type { Dossier } from '@/features/dossiers/types';
import {
    mockInertiaPage,
    mockUseFormatters,
    mockUseTranslation,
} from '@/test/mocks';

const state = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockInertiaPage(state.props),
    /** Pure render gate, same as the real `<Deferred>`. */
    Deferred: ({
        data,
        fallback,
        children,
    }: {
        data: string | string[];
        fallback: React.ReactNode;
        children: React.ReactNode;
    }) => {
        const keys = Array.isArray(data) ? data : [data];

        return keys.every((key) => state.props[key] !== undefined)
            ? children
            : fallback;
    },
}));

vi.mock('@/features/audio-recordings/audio-recording-upload-form', () => ({
    default: () => <div data-testid="upload-form" />,
}));

vi.mock('@/features/pipelines/hooks/use-fetch-on-pipeline-updated', () => ({
    useFetchOnPipelineUpdated: () => undefined,
}));

const { default: AudioRecordingsCard } =
    await import('@/features/dossiers/show/audio-recordings-card');

const dossier = { id: 7 } as Dossier;

function renderCard(audioRecordings: AudioRecording[] | undefined) {
    state.props = {
        current_firm: { id: 1, slug: 'acme', name: 'Acme' },
        ...(audioRecordings === undefined
            ? {}
            : { audio_recordings: audioRecordings }),
    };

    return render(
        <AudioRecordingsCard dossier={dossier} canUpload canDownload={false} />,
    );
}

beforeEach(() => {
    state.props = {};
});

describe('AudioRecordingsCard', () => {
    /**
     * The upload form owns `useForm` state, so it must sit outside the deferred
     * boundary: a rejected upload redirects back to a page object that re-defers
     * `audio_recordings`, and anything inside the boundary is unmounted while the
     * prop reloads — which is how the validation error went missing (TASK-42).
     */
    it('renders the upload form while the recordings list is still deferred', () => {
        renderCard(undefined);

        expect(screen.getByTestId('upload-form')).toBeInTheDocument();
    });

    it('drops the list skeleton once the recordings prop resolves empty', () => {
        renderCard([]);

        expect(screen.getByTestId('upload-form')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Loading audio recordings'),
        ).not.toBeInTheDocument();
    });
});
