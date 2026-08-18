import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { useTemplateLibraryTab } from '@/features/questionnaires/use-template-library-tab';

afterEach(() => {
    window.history.replaceState({}, '', '/');
});

describe('useTemplateLibraryTab', () => {
    it('defaults to system when no ?tab= is present', () => {
        window.history.replaceState({}, '', '/templates');

        const { result } = renderHook(() => useTemplateLibraryTab());

        expect(result.current[0]).toBe('system');
    });

    it('seeds ?tab= on first mount when it is missing', () => {
        window.history.replaceState({}, '', '/templates');

        renderHook(() => useTemplateLibraryTab());

        expect(window.location.search).toBe('?tab=system');
    });

    it('reads the initial tab from an existing ?tab=custom', () => {
        window.history.replaceState({}, '', '/templates?tab=custom');

        const { result } = renderHook(() => useTemplateLibraryTab());

        expect(result.current[0]).toBe('custom');
        // Already present — must not be rewritten.
        expect(window.location.search).toBe('?tab=custom');
    });

    it('setTab updates both state and the URL', () => {
        window.history.replaceState({}, '', '/templates?tab=system');

        const { result } = renderHook(() => useTemplateLibraryTab());

        act(() => {
            result.current[1]('custom');
        });

        expect(result.current[0]).toBe('custom');
        expect(window.location.search).toBe('?tab=custom');
    });
});
