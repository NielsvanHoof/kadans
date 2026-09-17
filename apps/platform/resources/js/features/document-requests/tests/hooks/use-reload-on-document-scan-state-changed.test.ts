import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';

/** Captured Echo handler so tests can emit the broadcast directly. */
let emit: () => void = () => {};
let subscribedEvent = '';
const routerReload = vi.fn();

vi.mock('@laravel/echo-react', () => ({
    // lib/echo.ts calls this on import; no-op under tests.
    configureEcho: vi.fn(),
    useEcho: (_channel: string, event: string, handler: () => void) => {
        subscribedEvent = event;
        emit = handler;
    },
}));
vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: routerReload },
}));

const { useReloadOnDocumentScanStateChanged } =
    await import('@/features/document-requests/hooks/use-reload-on-document-scan-state-changed');

beforeEach(() => {
    routerReload.mockReset();
});

describe('useReloadOnDocumentScanStateChanged', () => {
    /*
     * The whole point: someone who has just uploaded a document is looking at
     * the page while the check runs, and without this the file would sit on
     * "running security check" — showing no preview — until they reloaded.
     */
    it('reloads when a verdict arrives', () => {
        renderHook(() => useReloadOnDocumentScanStateChanged());

        emit();

        expect(routerReload).toHaveBeenCalledWith({ only: ['dossier'] });
    });

    /*
     * The channel is the whole firm, so a page with nothing in flight would
     * otherwise reload every time any colleague uploaded anything.
     */
    it('stays put when nothing on the page is waiting on a check', () => {
        renderHook(() =>
            useReloadOnDocumentScanStateChanged({ enabled: false }),
        );

        emit();

        expect(routerReload).not.toHaveBeenCalled();
    });

    it('reloads only the props the caller asks for', () => {
        renderHook(() =>
            useReloadOnDocumentScanStateChanged({ only: ['dossier', 'flash'] }),
        );

        emit();

        expect(routerReload).toHaveBeenCalledWith({
            only: ['dossier', 'flash'],
        });
    });

    /*
     * The event name must match DocumentScanStateChanged::broadcastAs(), with
     * the leading dot Echo needs for a custom name. A typo here fails silently:
     * the subscription succeeds and simply never fires.
     */
    it('subscribes to the broadcast name the server sends', () => {
        renderHook(() => useReloadOnDocumentScanStateChanged());

        expect(subscribedEvent).toBe('.document.scan.state_changed');
    });
});
