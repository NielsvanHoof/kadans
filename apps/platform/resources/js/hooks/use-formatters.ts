import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    formatDate,
    formatDateTime,
    formatDateTimeWithZone,
    formatRelativeTime,
} from '@/lib/format-date-time';

/**
 * Date formatters bound to the current locale and resolved timezone.
 *
 * Reading the zone here rather than at each call site means a call site cannot
 * forget it, and keeps `lib/format-date-time` free of Inertia imports so it stays
 * unit-testable with explicit zones.
 *
 * Memoised because callers use these inside effects — an unstable identity would
 * re-run those effects on every render.
 */
export function useFormatters() {
    const { locale, timezone } = usePage().props;

    return useMemo(
        () => ({
            /** An absolute instant, in the viewer's zone. */
            formatDateTime: (iso: string): string =>
                formatDateTime(iso, locale, timezone),

            /** As above, plus the zone abbreviation — for tooltips, where a bare time is ambiguous. */
            formatDateTimeWithZone: (iso: string): string =>
                formatDateTimeWithZone(iso, locale, timezone),

            /**
             * A calendar day. Deliberately not zone-converted — see
             * lib/format-date-time.ts. Do not "fix" this to use the timezone.
             */
            formatDate: (
                iso: string,
                options?: Intl.DateTimeFormatOptions,
            ): string => formatDate(iso, locale, options),

            /** Relative age ("2 hours ago"). Not hydration-safe; render after mount. */
            formatRelativeTime: (iso: string, now?: Date): string =>
                formatRelativeTime(iso, locale, now),
        }),
        [locale, timezone],
    );
}
