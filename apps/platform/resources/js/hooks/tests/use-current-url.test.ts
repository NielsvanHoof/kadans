import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage } from '@/test/mocks';

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockInertiaPage({}, '/workspaces/acme/dossiers/3'),
}));

const { useCurrentUrl } = await import('@/hooks/use-current-url');

describe('useCurrentUrl', () => {
    it('exposes the pathname from the Inertia page URL', () => {
        const { result } = renderHook(() => useCurrentUrl());

        expect(result.current.currentUrl).toBe('/workspaces/acme/dossiers/3');
    });

    it('matches exact paths and absolute URLs by pathname', () => {
        const { result } = renderHook(() => useCurrentUrl());

        expect(result.current.isCurrentUrl('/workspaces/acme/dossiers/3')).toBe(
            true,
        );
        expect(result.current.isCurrentUrl('/workspaces/acme/dossiers/9')).toBe(
            false,
        );
        expect(
            result.current.isCurrentUrl(
                'https://example.test/workspaces/acme/dossiers/3',
            ),
        ).toBe(true);
    });

    it('supports startsWith matching via isCurrentOrParentUrl', () => {
        const { result } = renderHook(() => useCurrentUrl());

        expect(
            result.current.isCurrentOrParentUrl('/workspaces/acme/dossiers'),
        ).toBe(true);
        expect(result.current.isCurrentOrParentUrl('/workspaces/other')).toBe(
            false,
        );
    });

    it('whenCurrentUrl picks the true branch only on an exact match', () => {
        const { result } = renderHook(() => useCurrentUrl());

        expect(
            result.current.whenCurrentUrl(
                '/workspaces/acme/dossiers/3',
                'active',
                'idle',
            ),
        ).toBe('active');
        expect(
            result.current.whenCurrentUrl(
                '/workspaces/acme/clients',
                'active',
                'idle',
            ),
        ).toBe('idle');
    });
});
