import { DateFormatter, parseDate } from '@internationalized/date';

/**
 * Format an absolute instant in an explicit timezone.
 *
 * The zone is a required argument, resolved server-side and delivered as an
 * Inertia prop, so a server render and the browser format the same instant the
 * same way. Never source it from `Intl.DateTimeFormat().resolvedOptions()` at
 * render time — the server has no browser zone, and the two would diverge during
 * hydration. Prefer the `useFormatters()` hook, which binds both arguments.
 */
export function formatDateTime(
    iso: string,
    locale: string,
    timeZone: string,
): string {
    return new DateFormatter(locale, {
        dateStyle: 'short',
        timeStyle: 'medium',
        timeZone,
    }).format(new Date(iso));
}

/**
 * As `formatDateTime`, with the zone abbreviation appended ("… CEST").
 *
 * For tooltips and anywhere a bare wall-clock time would be ambiguous.
 *
 * The zone is resolved by a second formatter and concatenated, rather than by
 * adding `timeZoneName` to the call above: ECMA-402 forbids combining
 * `dateStyle`/`timeStyle` with individual component options, and doing so throws
 * `TypeError: Invalid option`. A zone-only formatter is the supported way to get
 * the abbreviation on its own.
 */
export function formatDateTimeWithZone(
    iso: string,
    locale: string,
    timeZone: string,
): string {
    const formatted = formatDateTime(iso, locale, timeZone);

    const zoneName = new DateFormatter(locale, {
        timeZoneName: 'short',
        timeZone,
    })
        .formatToParts(new Date(iso))
        .find((part) => part.type === 'timeZoneName')?.value;

    return zoneName ? `${formatted} ${zoneName}` : formatted;
}

/**
 * Format a calendar date without a time component.
 *
 * Accepts `YYYY-MM-DD` or a longer ISO string — only the date part is used.
 * Parsed via `@internationalized/date` so the day never shifts across a
 * timezone boundary the way `new Date('YYYY-MM-DD')` can.
 *
 * ---
 * This function takes no timezone, deliberately. It renders calendar days
 * (`due_date`), which the backend serialises with `toDateString()` as a bare
 * `YYYY-MM-DD`. A deadline of "15 March" is 15 March in Auckland and in Los
 * Angeles alike — converting it to a viewer's zone would render 14 March for
 * everyone west of UTC. Do not add a `timeZone` parameter here.
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
        // Anchor at UTC midnight so the calendar day cannot shift.
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
 *
 * Timezone-agnostic by nature: it compares two instants. It only ever appeared
 * broken when the backend supplied an offset-less string, which the browser then
 * parsed as local time — see tests/Unit/SerializedTimestampFormatTest.php.
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
            return formatter.format(Math.round(durationSeconds), division.unit);
        }

        durationSeconds /= division.amount;
    }

    return formatter.format(Math.round(durationSeconds), 'year');
}
