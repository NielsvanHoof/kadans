import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';

const pageProps = { locale: 'en-GB', timezone: 'Europe/Amsterdam' };

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps, url: '/' }),
}));

const { useFormatters } = await import('@/hooks/use-formatters');

const instant = '2025-07-15T14:30:45.000Z';

describe('useFormatters', () => {
    it('formats instants in the zone from page props', () => {
        const { result } = renderHook(() => useFormatters());

        // 14:30 UTC is 16:30 in Amsterdam in July.
        expect(result.current.formatDateTime(instant)).toBe(
            '15/07/2025, 16:30:45',
        );
    });

    it('names the zone for tooltips', () => {
        const { result } = renderHook(() => useFormatters());

        expect(result.current.formatDateTimeWithZone(instant)).toBe(
            '15/07/2025, 16:30:45 CEST',
        );
    });

    it('leaves calendar days unconverted', () => {
        // The zone is +02:00 here; a converted calendar day would shift.
        const { result } = renderHook(() => useFormatters());

        expect(result.current.formatDate('2025-07-15')).toBe('15/07/2025');
    });

    it('keeps a stable identity across renders', () => {
        // Callers use these inside useEffect; a new identity each render would
        // re-run those effects continuously.
        const { result, rerender } = renderHook(() => useFormatters());
        const first = result.current;

        rerender();

        expect(result.current).toBe(first);
    });
});
