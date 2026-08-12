import { DateFormatter, parseDate } from '@internationalized/date';

/**
 * Format an ISO timestamp the same on SSR and in the browser.
 * Uses UTC so server/client timezones cannot diverge during hydration.
 * Pass the current Inertia locale (from useTranslation) for language.
 */
export function formatDateTime(iso: string, locale: string): string {
    return new DateFormatter(locale, {
        dateStyle: 'short',
        timeStyle: 'medium',
        timeZone: 'UTC',
    }).format(new Date(iso));
}

/**
 * Format a calendar date without a time component.
 *
 * Accepts `YYYY-MM-DD` or a longer ISO string — only the date part is used.
 * Parsed via `@internationalized/date` so the day never shifts across a
 * timezone boundary the way `new Date('YYYY-MM-DD')` can.
 */
export function formatDate(
    iso: string,
    locale: string,
    options: Intl.DateTimeFormatOptions = { dateStyle: 'short' },
): string {
    // Calendar day only — ignore any time / offset suffix.
    const date = parseDate(iso.slice(0, 10));

    return new DateFormatter(locale, {
        ...options,
        // Anchor at UTC midnight so SSR and the browser agree.
        timeZone: 'UTC',
    }).format(date.toDate('UTC'));
}

type RelativeUnit = Intl.RelativeTimeFormatUnit;

const RelativeDivisions: ReadonlyArray<{
    amount: number;
    unit: RelativeUnit;
}> = [
    { amount: 60, unit: 'second' },
    { amount: 60, unit: 'minute' },
    { amount: 24, unit: 'hour' },
    { amount: 7, unit: 'day' },
    { amount: 4.34524, unit: 'week' },
    { amount: 12, unit: 'month' },
    { amount: Number.POSITIVE_INFINITY, unit: 'year' },
];

/**
 * Human relative time for inbox rows ("2 hours ago").
 * Pass `now` in tests so the result is deterministic.
 * Not hydration-safe — render after mount; use formatDateTime for SSR/title.
 */
export function formatRelativeTime(
    iso: string,
    locale: string,
    now: Date = new Date(),
): string {
    const then = new Date(iso);
    let durationSeconds = (then.getTime() - now.getTime()) / 1000;

    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const division of RelativeDivisions) {
        if (Math.abs(durationSeconds) < division.amount) {
            return formatter.format(
                Math.round(durationSeconds),
                division.unit,
            );
        }

        durationSeconds /= division.amount;
    }

    return formatter.format(Math.round(durationSeconds), 'year');
}
