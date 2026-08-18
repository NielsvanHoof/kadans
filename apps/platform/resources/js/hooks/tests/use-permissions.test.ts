import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { mockInertiaPage } from '@/test/mocks';

// Permissions come from the shared Inertia page prop — swap usePage per case.
vi.mock('@inertiajs/react', () => ({
    usePage: () =>
        mockInertiaPage({
            permissions: {
                'dossiers.view': true,
                'dossiers.delete': false,
            },
        }),
}));

const { usePermissions } = await import('@/hooks/use-permissions');

describe('usePermissions', () => {
    it('returns true only when the page prop is explicitly true', () => {
        const { result } = renderHook(() => usePermissions());

        expect(result.current.can('dossiers.view')).toBe(true);
        expect(result.current.can('dossiers.delete')).toBe(false);
        // Absent keys are treated as denied (outside a firm, the map is empty).
        expect(result.current.can('dossiers.create')).toBe(false);
    });
});
