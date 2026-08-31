import { render, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage } from '@/test/mocks';

/**
 * Captured Echo subscriptions, so tests can emit broadcasts directly and assert
 * which channels this hook joins. It mounts in AppLayout, so whatever it
 * subscribes to, every staff member subscribes to on every page.
 */
const echoHandlers: Record<string, (event: unknown) => void> = {};
const echoChannels: string[] = [];
const routerReload = vi.fn();
let pageProps: Record<string, unknown> = {};

vi.mock('@laravel/echo-react', () => ({
    // lib/echo.ts calls this on import; no-op under tests.
    configureEcho: vi.fn(),
    useEcho: (
        channel: string,
        event: string,
        handler: (event: unknown) => void,
    ) => {
        echoHandlers[event] = handler;
        echoChannels.push(channel);
    },
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: routerReload },
    usePage: () => mockInertiaPage(pageProps),
}));

const { useWorkspaceNotifications, WorkspaceNotifications } =
    await import('@/features/notifications/use-workspace-notifications');

beforeEach(() => {
    routerReload.mockReset();
    echoChannels.length = 0;
    for (const key of Object.keys(echoHandlers)) {
        delete echoHandlers[key];
    }
});

describe('useWorkspaceNotifications', () => {
    it('reloads the notifications prop on a questionnaire.completed broadcast', () => {
        renderHook(() => useWorkspaceNotifications('acme'));

        echoHandlers['.client.questionnaire.completed']({});

        expect(routerReload).toHaveBeenCalledWith({ only: ['notifications'] });
    });

    it('reloads on a completed audio transcription broadcast', () => {
        renderHook(() => useWorkspaceNotifications('acme'));

        echoHandlers['.audio.transcription.completed']({
            audio_recording_id: 1,
            dossier_id: 1,
        });

        expect(routerReload).toHaveBeenCalledWith({ only: ['notifications'] });
    });

    it('does not subscribe to the pipeline stream', () => {
        renderHook(() => useWorkspaceNotifications('acme'));

        // The regression this guards: the bell used to filter the whole
        // pipeline.updated stream for completed transcriptions. Because it
        // mounts in AppLayout, that subscribed every staff member to every run
        // in the firm on every page — and the Pusher protocol delivers a
        // channel's events to all its subscribers whether or not a listener is
        // bound, so a bulk upload woke every open tab hundreds of times.
        expect(echoHandlers['.pipeline.updated']).toBeUndefined();
        // Two useEcho calls, one per event, but only the one channel — Echo
        // dedupes the underlying subscription.
        expect([...new Set(echoChannels)]).toEqual(['workspaces.acme']);
    });
});

describe('WorkspaceNotifications', () => {
    it('renders nothing when there is no authenticated user', () => {
        pageProps = { auth: { user: null }, current_firm: { slug: 'acme' } };

        const { container } = render(<WorkspaceNotifications />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing outside a workspace', () => {
        pageProps = { auth: { user: { id: 1 } }, current_firm: null };

        const { container } = render(<WorkspaceNotifications />);

        expect(container).toBeEmptyDOMElement();
    });

    it('mounts the Echo subscription inside a workspace', () => {
        pageProps = {
            auth: { user: { id: 1 } },
            current_firm: { slug: 'acme' },
        };

        render(<WorkspaceNotifications />);

        expect(echoHandlers['.client.questionnaire.completed']).toBeDefined();
    });
});
