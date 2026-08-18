import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { setClientCookie } from '@/lib/set-client-cookie';

/**
 * Reports the browser's timezone to the server via cookie.
 *
 * No HTTP header carries a timezone, so this is the only way the server can learn
 * it. The detected value is written to a cookie rather than used for rendering:
 * rendering reads the resolved `timezone` page prop, so the server and the browser
 * always agree.
 *
 * On a brand-new session the server has nothing to go on, so the first render
 * uses `app.display_timezone` and every request after this effect picks up the
 * real zone. That one-render staleness is deliberate — with a sensible configured
 * default it is invisible, and forcing a reload would cost every visitor a second
 * request to correct a minority's single page view.
 */
export function useTimezoneSync(): void {
    const { timezone } = usePage().props;

    useEffect(() => {
        const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;

        // Already agreed — skip the write so it does not run on every navigation.
        if (!detected || detected === timezone) {
            return;
        }

        setClientCookie('timezone', detected);
    }, [timezone]);
}
