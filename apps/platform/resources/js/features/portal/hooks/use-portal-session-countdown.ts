import { useEffect, useState } from 'react';

/** Warn this far out. Long enough to finish a field, short enough to not nag. */
const WARN_WITHIN_SECONDS = 180;

const TICK_MS = 1000;

export type PortalSessionCountdown = {
    /** Whole seconds left on the idle deadline, floored at zero. */
    secondsRemaining: number;
    /** Inside the warning window, and not yet over. */
    isExpiringSoon: boolean;
    /** The deadline has passed as far as the browser can tell. */
    hasEnded: boolean;
};

function secondsUntil(iso: string, now: number): number {
    const deadline = Date.parse(iso);

    if (Number.isNaN(deadline)) {
        return Number.POSITIVE_INFINITY;
    }

    return Math.max(0, Math.floor((deadline - now) / 1000));
}

/**
 * Counts down the client's idle deadline so the portal can warn them before a
 * click fails, instead of letting them discover it by losing the page.
 *
 * The clock is only ever advisory — the server owns the real deadline, and any
 * portal request slides it forward. So a skewed browser clock costs a premature
 * or late banner, never access.
 *
 * Stops ticking once it reaches zero: there is nothing left to count, and the
 * timer would otherwise run for as long as the tab stays open.
 */
export function usePortalSessionCountdown(
    idleExpiresAt: string,
): PortalSessionCountdown {
    const [secondsRemaining, setSecondsRemaining] = useState(() =>
        secondsUntil(idleExpiresAt, Date.now()),
    );

    useEffect(() => {
        // Re-seed synchronously: a new deadline (the server slid the window on
        // the last visit) must not wait a whole tick to be reflected.
        setSecondsRemaining(secondsUntil(idleExpiresAt, Date.now()));

        const interval = window.setInterval(() => {
            const remaining = secondsUntil(idleExpiresAt, Date.now());

            setSecondsRemaining(remaining);

            if (remaining === 0) {
                window.clearInterval(interval);
            }
        }, TICK_MS);

        return () => window.clearInterval(interval);
    }, [idleExpiresAt]);

    return {
        secondsRemaining,
        isExpiringSoon:
            secondsRemaining > 0 && secondsRemaining <= WARN_WITHIN_SECONDS,
        hasEnded: secondsRemaining === 0,
    };
}
