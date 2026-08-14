import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import {
    formatDate,
    formatDateTime,
    formatDateTimeWithZone,
    formatRelativeTime,
} from '@/lib/format-date-time';

describe('formatDate', () => {
    it('formats a plain YYYY-MM-DD calendar day', () => {
        expect(formatDate('2025-03-15', 'en-GB')).toBe('15/03/2025');
        expect(formatDate('2025-03-15', 'nl-NL')).toBe('15-03-2025');
    });

    it('uses only the date part of a longer ISO string', () => {
        // Without parseDate, local-midnight parsing can shift the calendar day.
        expect(formatDate('2025-03-15T23:00:00.000Z', 'en-GB')).toBe(
            '15/03/2025',
        );
        expect(formatDate('2025-03-15T00:00:00Z', 'en-GB')).toBe('15/03/2025');
    });

    it('takes no timezone, so a calendar day never shifts', () => {
        // A due date of "15 March" is 15 March everywhere. formatDate must not
        // grow a timeZone parameter — see the note in lib/format-date-time.ts.
        // Asserted from both sides of UTC, where a zone-converted value would
        // render 14/03 and 16/03 respectively.
        expect(formatDate('2025-03-15', 'en-GB')).toBe('15/03/2025');
        expect(formatDate('2025-03-15T00:00:00+13:00', 'en-GB')).toBe(
            '15/03/2025',
        );
        expect(formatDate('2025-03-15T00:00:00-07:00', 'en-GB')).toBe(
            '15/03/2025',
        );
    });

    it('supports a longer display style for pickers', () => {
        expect(
            formatDate('2025-03-15', 'en-GB', { dateStyle: 'long' }),
        ).toMatch(/15.*March.*2025/);
    });
});

describe('formatDateTime', () => {
    // A single fixed instant rendered in several zones. The timezone is an
    // explicit argument, so these never depend on the machine timezone.
    const instant = '2025-03-15T14:30:45.000Z';

    it('renders the instant in the given timezone', () => {
        expect(formatDateTime(instant, 'en-GB', 'UTC')).toBe(
            '15/03/2025, 14:30:45',
        );
        // CET in March: +01:00.
        expect(formatDateTime(instant, 'en-GB', 'Europe/Amsterdam')).toBe(
            '15/03/2025, 15:30:45',
        );
        expect(formatDateTime(instant, 'en-GB', 'America/New_York')).toBe(
            '15/03/2025, 10:30:45',
        );
    });

    it('follows daylight saving in the target zone', () => {
        // CEST in July: +02:00, an hour further ahead than the March case above.
        expect(
            formatDateTime('2025-07-15T14:30:45.000Z', 'en-GB', 'Europe/Amsterdam'),
        ).toBe('15/07/2025, 16:30:45');
    });

    it('rolls the date when the zone crosses midnight', () => {
        expect(
            formatDateTime('2025-03-15T23:30:00.000Z', 'en-GB', 'Asia/Tokyo'),
        ).toBe('16/03/2025, 08:30:00');
    });

});

describe('formatDateTimeWithZone', () => {
    it('appends the zone abbreviation', () => {
        // Summer, so the abbreviation is the DST one.
        const formatted = formatDateTimeWithZone(
            '2025-07-15T14:30:45.000Z',
            'en-GB',
            'Europe/Amsterdam',
        );

        expect(formatted).toBe('15/07/2025, 16:30:45 CEST');
    });

    it('reports the winter abbreviation for a winter instant', () => {
        expect(
            formatDateTimeWithZone(
                '2025-01-15T14:30:45.000Z',
                'en-GB',
                'Europe/Amsterdam',
            ),
        ).toBe('15/01/2025, 15:30:45 CET');
    });

    it('does not throw on a dateStyle + timeZoneName combination', () => {
        // ECMA-402 rejects mixing dateStyle/timeStyle with component options such
        // as timeZoneName ("TypeError: Invalid option"). This helper exists to
        // sidestep that with a second, zone-only formatter — so the guarantee
        // worth asserting is simply that it returns a string instead of throwing.
        expect(() =>
            formatDateTimeWithZone('2025-03-15T14:30:45.000Z', 'nl', 'Asia/Tokyo'),
        ).not.toThrow();
    });
});

describe('formatRelativeTime', () => {
    const now = new Date('2025-03-15T14:00:00.000Z');

    it('formats minutes ago', () => {
        expect(formatRelativeTime('2025-03-15T13:45:00.000Z', 'en', now)).toBe(
            '15 minutes ago',
        );
    });

    it('formats hours ago', () => {
        expect(formatRelativeTime('2025-03-15T12:00:00.000Z', 'en', now)).toBe(
            '2 hours ago',
        );
    });

    it('formats in the viewing locale', () => {
        expect(
            formatRelativeTime('2025-03-15T13:00:00.000Z', 'nl', now),
        ).toMatch(/uur/);
    });

});

// The test runner is UTC, where an offset-less string and an offset-bearing one
// parse to the same instant — so under the default TZ these assertions cannot
// fail and would not have caught the original bug. Pin a +02:00 zone so the
// difference is observable.
describe('formatRelativeTime outside UTC', () => {
    // July, so Europe/Amsterdam is CEST (+02:00) rather than CET (+01:00).
    const now = new Date('2025-07-15T14:00:00.000Z');

    beforeAll(() => {
        vi.stubEnv('TZ', 'Europe/Amsterdam');
    });

    afterAll(() => {
        vi.unstubAllEnvs();
    });

    it('reads an offset-bearing timestamp as the instant it names', () => {
        expect(formatRelativeTime('2025-07-15T13:52:00+00:00', 'en', now)).toBe(
            '8 minutes ago',
        );
    });

    it('misreads an offset-less timestamp as local time', () => {
        // Documents the defect this replaced: identical wall-clock digits with no
        // offset are parsed by V8 as 13:52 Amsterdam = 11:52 UTC, putting the
        // event 2h08m before `now`. That is how an 8-minute-old notification
        // rendered as "2 uur geleden". Backends must emit toIso8601String();
        // enforced by tests/Unit/SerializedTimestampFormatTest.php.
        expect(formatRelativeTime('2025-07-15 13:52:00', 'en', now)).toBe(
            '2 hours ago',
        );
    });
});
