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
