import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { DocumentRequest } from '@/features/document-requests/types';
import type { DossierPanel } from '@/features/dossiers/show/dossier-panel';
import type { Dossier } from '@/features/dossiers/types';
import type { AccessGrant } from '@/features/portal/types';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const state = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

const useDossierCapabilities = vi.fn();

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/features/dossiers/permissions/dossier-permissions-context', () => ({
    useDossierCapabilities,
}));

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
        <div data-testid="audio-card">
            {canUpload ? 'can-upload' : 'read-only'}
        </div>
    ),
}));

vi.mock('@/features/dossiers/show/dossier-export-card', () => ({
    default: () => <div data-testid="export-card" />,
}));

vi.mock('@/features/dossiers/show/waiting-items-summary', () => ({
    default: () => <div data-testid="waiting-list" />,
}));

vi.mock('@/features/portal/client-access-card', () => ({
    default: () => <div data-testid="access-card" />,
}));

vi.mock('@/features/portal/portal-link-banner', () => ({
    default: () => <div data-testid="portal-link" />,
}));

const { default: DossierPanels } =
    await import('@/features/dossiers/show/dossier-panels');

function documentRequest(
    status: DocumentRequest['status'],
    isApplicable = true,
): DocumentRequest {
    return {
        id: Math.random(),
        title: 'Balans',
        status,
        is_applicable: isApplicable,
    } as DocumentRequest;
}

function dossier(overrides: Partial<Dossier> = {}): Dossier {
    return {
        id: 7,
        status: 'in_review',
        client: { name: 'Biemans B.V.', email: 'info@biemans.test' },
        document_requests: [],
        access_grants: [],
        exports_count: 0,
        audio_recordings_count: 0,
        ...overrides,
    } as Dossier;
}

type Capabilities = {
    canDownload?: boolean;
    canCreateInvite?: boolean;
    canUploadAudio?: boolean;
    canDownloadAudio?: boolean;
};

function renderPanels({
    panel = 'waiting',
    audioRecordings,
    capabilities = {},
    accessGrantPortalUrl = null,
    ...overrides
}: {
    panel?: DossierPanel;
    audioRecordings?: unknown[];
    capabilities?: Capabilities;
    accessGrantPortalUrl?: string | null;
} & Partial<Dossier> = {}) {
    state.props = {
        current_firm: { slug: 'acme', name: 'Acme' },
        ...(audioRecordings === undefined
            ? {}
            : { audio_recordings: audioRecordings }),
    };

    useDossierCapabilities.mockReturnValue({
        canDownload: false,
        canCreateInvite: true,
        canUploadAudio: false,
        canDownloadAudio: false,
        ...capabilities,
    });

    return render(
        <DossierPanels
            dossier={dossier(overrides)}
            panel={panel}
            onSelectPanel={vi.fn()}
            accessGrantToken={null}
            accessGrantPortalUrl={accessGrantPortalUrl}
            hasFormItems
        />,
    );
}

function tabNames(): string[] {
    return screen
        .getAllByRole('tab')
        .map((tab) => tab.textContent?.trim() ?? '');
}

beforeEach(() => {
    state.props = {};
    useDossierCapabilities.mockReset();
});

describe('DossierPanels', () => {
    /**
     * The regression this pins (TASK-42): the audio card used to sit inside
     * `<Deferred data="audio_recordings">`. A rejected upload redirects back to
     * a page object that re-declares the prop as deferred, so the boundary
     * replaced the card — and its `useForm` state — with a skeleton, discarding
     * the validation error before it could ever paint. The tabbed layout must
     * not reintroduce that: the panel is selected from component state, and the
     * card is never gated on the deferred prop.
     */
    it('keeps the audio card mounted while the recordings prop is still deferred', () => {
        renderPanels({
            panel: 'recordings',
            audioRecordings: undefined,
            capabilities: { canUploadAudio: true },
        });

        expect(screen.getByTestId('audio-card')).toBeInTheDocument();
        expect(screen.getByText('can-upload')).toBeInTheDocument();
    });

    it('shows the recordings tab for an uploader with no recordings yet', () => {
        renderPanels({
            audioRecordings: [],
            capabilities: { canUploadAudio: true },
        });

        expect(tabNames()).toContain('Recordings');
    });

    it('hides the recordings tab for a read-only viewer with no recordings', () => {
        renderPanels({ audioRecordings: [], capabilities: {} });

        expect(tabNames()).not.toContain('Recordings');
    });

    it('shows the recordings tab for a read-only viewer once recordings exist', () => {
        renderPanels({
            panel: 'recordings',
            audio_recordings_count: 2,
            audioRecordings: [{ id: 1 }],
        });

        expect(tabNames()).toContain('Recordings2');
        expect(screen.getByText('read-only')).toBeInTheDocument();
    });

    /**
     * Visibility is decided by the synchronous `*_count` props, never by the
     * deferred rows, so the tab strip cannot change shape after first paint.
     */
    it('shows the recordings tab before the deferred rows arrive', () => {
        renderPanels({
            audio_recordings_count: 2,
            audioRecordings: undefined,
        });

        expect(tabNames()).toContain('Recordings2');
    });

    it('hides the exports tab without download permission', () => {
        renderPanels();

        expect(tabNames()).not.toContain('Exports');
    });

    it('shows the exports tab and its count with download permission', () => {
        renderPanels({
            exports_count: 3,
            capabilities: { canDownload: true },
        });

        expect(tabNames()).toContain('Exports3');
    });

    it('omits a count of zero so a number only appears when there is one', () => {
        renderPanels({
            document_requests: [],
            capabilities: { canDownload: true },
        });

        expect(tabNames()).toContain('Waiting');
        expect(tabNames()).toContain('Exports');
    });

    it('counts the items the client still owes', () => {
        renderPanels({
            document_requests: [
                documentRequest('pending'),
                documentRequest('rejected'),
                documentRequest('accepted'),
            ],
        });

        expect(tabNames()).toContain('Waiting2');
    });

    /**
     * A field on an untriggered branch is not work the client can do. The
     * review queue and `has_outstanding_client_items` both ignore it, so the
     * tab must too — otherwise the strip reports work the rest of the page
     * does not acknowledge.
     */
    it('leaves out unanswered items the client is not being asked for', () => {
        renderPanels({
            document_requests: [
                documentRequest('pending'),
                documentRequest('pending', false),
                documentRequest('rejected', false),
            ],
        });

        expect(tabNames()).toContain('Waiting1');
    });

    it('counts only valid access grants', () => {
        renderPanels({
            access_grants: [
                { id: 1, is_valid: true } as AccessGrant,
                { id: 2, is_valid: false } as AccessGrant,
            ],
        });

        expect(tabNames()).toContain('Access1');
    });

    it('falls back to the waiting panel when the requested one is unavailable', () => {
        renderPanels({ panel: 'exports' });

        expect(screen.getByTestId('waiting-list')).toBeInTheDocument();
        expect(screen.queryByTestId('export-card')).not.toBeInTheDocument();
    });

    it('shows the one-time portal link on the access panel', () => {
        renderPanels({
            panel: 'access',
            accessGrantPortalUrl: 'https://portal.test/abc',
        });

        expect(screen.getByTestId('portal-link')).toBeInTheDocument();
        expect(screen.getByTestId('access-card')).toBeInTheDocument();
    });
});
