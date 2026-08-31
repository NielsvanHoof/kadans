import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vite-plus/test';

const { initializeTheme, useAppearance } =
    await import('@/hooks/use-appearance');

beforeEach(() => {
    localStorage.clear();
    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = '';
});

describe('initializeTheme', () => {
    it('defaults missing storage to system and applies light when prefers-color-scheme is light', () => {
        initializeTheme();

        expect(localStorage.getItem('appearance')).toBe('system');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.style.colorScheme).toBe('light');
    });
});

describe('useAppearance', () => {
    it('persists the chosen mode and toggles the dark class', () => {
        // Seed module-level state from storage before the hook mounts.
        initializeTheme();
        const { result } = renderHook(() => useAppearance());

        act(() => {
            result.current.updateAppearance('dark');
        });

        expect(result.current.appearance).toBe('dark');
        expect(result.current.resolvedAppearance).toBe('dark');
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');

        act(() => {
            result.current.updateAppearance('light');
        });

        expect(result.current.appearance).toBe('light');
        expect(result.current.resolvedAppearance).toBe('light');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.style.colorScheme).toBe('light');
    });
});
