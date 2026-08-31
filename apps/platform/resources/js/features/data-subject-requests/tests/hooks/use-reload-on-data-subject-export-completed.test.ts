import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';

/** Captured Echo handler so tests can emit the broadcast directly. */
let emit: () => void = () => {};
const routerReload = vi.fn();

vi.mock('@laravel/echo-react', () => ({
    // lib/echo.ts calls this on import; no-op under tests.
    configureEcho: vi.fn(),
    useEcho: (_channel: string, _event: string, handler: () => void) => {
        emit = handler;
    },
}));
vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: routerReload },
}));

const { useReloadOnDataSubjectExportCompleted } =
    await import('@/features/data-subject-requests/hooks/use-reload-on-data-subject-export-completed');

beforeEach(() => {
    routerReload.mockReset();
});

describe('useReloadOnDataSubjectExportCompleted', () => {
    it('reloads the requests prop when an export completes', () => {
        renderHook(() => useReloadOnDataSubjectExportCompleted());

        emit();

        expect(routerReload).toHaveBeenCalledWith({ only: ['requests'] });
    });

    it('reloads only the props the caller asks for', () => {
        renderHook(() =>
            useReloadOnDataSubjectExportCompleted(['requests', 'flash']),
        );

        emit();

        expect(routerReload).toHaveBeenCalledWith({
            only: ['requests', 'flash'],
        });
    });
});
