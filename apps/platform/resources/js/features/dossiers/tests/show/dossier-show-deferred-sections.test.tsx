import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AudioRecording } from '@/features/audio-recordings/types';
import type { Dossier } from '@/features/dossiers/types';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const state = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockInertiaPage(state.props),
    /**
     * Mirrors the real `<Deferred>`: it is a pure render gate that shows the
     * fallback until every key is present in the page props. The request itself
     * is issued by Inertia core from the page object's `deferredProps`, which is
     * why a boundary around a form silently unmounts it on a redirect back.
     */
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

vi.mock('@/features/dossiers/show/audio-recordings-card', () => ({
    default: ({ canUpload }: { canUpload: boolean }) => (
        <div data-testid="audio-card">{canUpload ? 'can-upload' : 'read-only'}</div>
    ),
}));

vi.mock('@/features/dossiers/show/dossier-export-card', () => ({
    default: () => <div data-testid="export-card" />,
}));

const { default: DossierShowDeferredSections } = await import(
    '@/features/dossiers/show/dossier-show-deferred-sections'
);

const dossier = { id: 7 } as Dossier;

function renderSections({
    audioRecordings,
    canUploadAudio,
}: {
    audioRecordings: AudioRecording[] | undefined;
    canUploadAudio: boolean;
}) {
    state.props = {
        current_firm: { slug: 'acme', name: 'Acme' },
        ...(audioRecordings === undefined
            ? {}
            : { audio_recordings: audioRecordings }),
    };

    return render(
        <DossierShowDeferredSections
            dossier={dossier}
            canDownload={false}
            canUploadAudio={canUploadAudio}
            canDownloadAudio={false}
        />,
    );
}

beforeEach(() => {
    state.props = {};
});

describe('DossierShowDeferredSections', () => {
    /**
     * The regression this pins (TASK-42): the audio card used to sit inside
     * `<Deferred data="audio_recordings">`. A rejected upload redirects back to
     * a page object that re-declares the prop as deferred, so the boundary
     * replaced the card — and its `useForm` state — with a skeleton, discarding
     * the validation error before it could ever paint.
     */
    it('keeps the audio card mounted while the recordings prop is still deferred', () => {
        renderSections({ audioRecordings: undefined, canUploadAudio: true });

        expect(screen.getByTestId('audio-card')).toBeInTheDocument();
        expect(screen.getByText('can-upload')).toBeInTheDocument();
    });

    it('shows the audio card for an uploader with no recordings yet', () => {
        renderSections({ audioRecordings: [], canUploadAudio: true });

        expect(screen.getByTestId('audio-card')).toBeInTheDocument();
    });

    it('hides the audio card for a read-only viewer with no recordings', () => {
        renderSections({ audioRecordings: [], canUploadAudio: false });

        expect(screen.queryByTestId('audio-card')).not.toBeInTheDocument();
    });

    it('shows the audio card for a read-only viewer once recordings exist', () => {
        renderSections({
            audioRecordings: [{ id: 1 } as AudioRecording],
            canUploadAudio: false,
        });

        expect(screen.getByText('read-only')).toBeInTheDocument();
    });
});
