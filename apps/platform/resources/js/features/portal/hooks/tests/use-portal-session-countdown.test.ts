import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { usePortalSessionCountdown } from '@/features/portal/hooks/use-portal-session-countdown';

const NOW = new Date('2026-08-21T10:00:00.000Z');

function isoInSeconds(seconds: number): string {
    return new Date(NOW.getTime() + seconds * 1000).toISOString();
}

describe('usePortalSessionCountdown', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(NOW);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('stays quiet while the deadline is comfortably away', () => {
        const { result } = renderHook(() =>
            usePortalSessionCountdown(isoInSeconds(900)),
        );

        expect(result.current.secondsRemaining).toBe(900);
        expect(result.current.isExpiringSoon).toBe(false);
        expect(result.current.hasEnded).toBe(false);
    });

    it('warns once the deadline is within three minutes', () => {
        const { result } = renderHook(() =>
            usePortalSessionCountdown(isoInSeconds(180)),
        );

        expect(result.current.isExpiringSoon).toBe(true);
        expect(result.current.hasEnded).toBe(false);
    });

    it('counts down as time passes', () => {
        const { result } = renderHook(() =>
            usePortalSessionCountdown(isoInSeconds(200)),
        );

        act(() => {
            vi.advanceTimersByTime(30_000);
        });

        expect(result.current.secondsRemaining).toBe(170);
        expect(result.current.isExpiringSoon).toBe(true);
    });

    it('reports the end exactly once the deadline passes', () => {
        const { result } = renderHook(() =>
            usePortalSessionCountdown(isoInSeconds(5)),
        );

        act(() => {
            vi.advanceTimersByTime(5_000);
        });

        expect(result.current.secondsRemaining).toBe(0);
        expect(result.current.hasEnded).toBe(true);
        // Ended is not "expiring soon" — the banner shows different copy.
        expect(result.current.isExpiringSoon).toBe(false);
    });

    it('floors at zero rather than counting into negatives', () => {
        const { result } = renderHook(() =>
            usePortalSessionCountdown(isoInSeconds(-600)),
        );

        expect(result.current.secondsRemaining).toBe(0);
        expect(result.current.hasEnded).toBe(true);
    });

    it('stops ticking once it reaches zero', () => {
        const clearInterval = vi.spyOn(window, 'clearInterval');

        renderHook(() => usePortalSessionCountdown(isoInSeconds(2)));

        act(() => {
            vi.advanceTimersByTime(2_000);
        });

        // Otherwise the timer runs for as long as the tab stays open.
        expect(clearInterval).toHaveBeenCalled();
    });

    it('re-seeds immediately when the server slides the deadline', () => {
        const { result, rerender } = renderHook(
            ({ deadline }: { deadline: string }) =>
                usePortalSessionCountdown(deadline),
            { initialProps: { deadline: isoInSeconds(60) } },
        );

        expect(result.current.isExpiringSoon).toBe(true);

        // What a successful "Keep my session open" round trip looks like: a new
        // deadline must not wait a whole tick to be reflected.
        rerender({ deadline: isoInSeconds(900) });

        expect(result.current.secondsRemaining).toBe(900);
        expect(result.current.isExpiringSoon).toBe(false);
    });

    it('treats an unparseable deadline as no deadline, never as expired', () => {
        const { result } = renderHook(() =>
            usePortalSessionCountdown('not-a-date'),
        );

        // Failing the other way would lock a working client out of the form.
        expect(result.current.hasEnded).toBe(false);
        expect(result.current.isExpiringSoon).toBe(false);
    });
});
