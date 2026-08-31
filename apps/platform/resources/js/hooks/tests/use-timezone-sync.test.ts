import { renderHook } from '@testing-library/react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';

const pageProps = { timezone: 'Europe/Amsterdam' };

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps, url: '/' }),
}));

const setClientCookie = vi.fn();

vi.mock('@/lib/set-client-cookie', () => ({
    setClientCookie: (...args: unknown[]) => setClientCookie(...args),
}));

const { useTimezoneSync } = await import('@/hooks/use-timezone-sync');

function stubDetectedTimezone(timeZone: string | undefined): void {
    vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue({
        resolvedOptions: () => ({ timeZone }),
    } as unknown as Intl.DateTimeFormat);
}

beforeEach(() => {
    setClientCookie.mockReset();
    pageProps.timezone = 'Europe/Amsterdam';
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('useTimezoneSync', () => {
    it('reports a browser zone the server does not know yet', () => {
        stubDetectedTimezone('Asia/Tokyo');

        renderHook(() => useTimezoneSync());

        expect(setClientCookie).toHaveBeenCalledWith('timezone', 'Asia/Tokyo');
    });

    it('stays quiet when the server already resolved the same zone', () => {
        // Otherwise the cookie would be rewritten on every Inertia navigation.
        stubDetectedTimezone('Europe/Amsterdam');

        renderHook(() => useTimezoneSync());

        expect(setClientCookie).not.toHaveBeenCalled();
    });

    it('stays quiet when the browser reports no zone', () => {
        stubDetectedTimezone(undefined);

        renderHook(() => useTimezoneSync());

        expect(setClientCookie).not.toHaveBeenCalled();
    });
});
