import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { useUrlTab } from '@/hooks/use-url-tab';

type Panel = 'waiting' | 'access';

function isPanel(value: string): value is Panel {
    return value === 'waiting' || value === 'access';
}

function renderPanelTab(fallback: Panel = 'waiting') {
    return renderHook(() => useUrlTab<Panel>('tab', isPanel, fallback));
}

afterEach(() => {
    window.history.replaceState({}, '', '/');
});

describe('useUrlTab', () => {
    it('falls back when no parameter is present', () => {
        window.history.replaceState({}, '', '/dossiers/2');

        const { result } = renderPanelTab();

        expect(result.current[0]).toBe('waiting');
    });

    it('seeds the parameter on first mount when it is missing', () => {
        window.history.replaceState({}, '', '/dossiers/2');

        renderPanelTab();

        expect(window.location.search).toBe('?tab=waiting');
    });

    it('reads the initial tab from an existing parameter', () => {
        window.history.replaceState({}, '', '/dossiers/2?tab=access');

        const { result } = renderPanelTab();

        expect(result.current[0]).toBe('access');
        // Already present — must not be rewritten.
        expect(window.location.search).toBe('?tab=access');
    });

    it('ignores a parameter value that is not a known tab', () => {
        window.history.replaceState({}, '', '/dossiers/2?tab=nope');

        const { result } = renderPanelTab();

        expect(result.current[0]).toBe('waiting');
        expect(window.location.search).toBe('?tab=waiting');
    });

    it('preserves other query parameters when writing', () => {
        window.history.replaceState({}, '', '/dossiers/2?filter[status]=open');

        const { result } = renderPanelTab();

        act(() => {
            result.current[1]('access');
        });

        expect(window.location.search).toContain('filter%5Bstatus%5D=open');
        expect(window.location.search).toContain('tab=access');
    });

    it('setTab updates both state and the URL', () => {
        window.history.replaceState({}, '', '/dossiers/2?tab=waiting');

        const { result } = renderPanelTab();

        act(() => {
            result.current[1]('access');
        });

        expect(result.current[0]).toBe('access');
        expect(window.location.search).toBe('?tab=access');
    });

    /**
     * The TASK-42 guarantee: a validation redirect back lands on a URL with no
     * `?tab=` (a replaceState never reaches the server). The hook must keep the
     * selected tab in React state so the panel — and any form inside it — stays
     * mounted to paint its inline error.
     */
    it('keeps the selected tab when the parameter disappears from the URL', () => {
        window.history.replaceState({}, '', '/dossiers/2?tab=waiting');

        const { result, rerender } = renderPanelTab();

        act(() => {
            result.current[1]('access');
        });

        // Stand-in for Laravel's redirect back to url()->previous().
        window.history.replaceState({}, '', '/dossiers/2');
        rerender();

        expect(result.current[0]).toBe('access');
    });
});
