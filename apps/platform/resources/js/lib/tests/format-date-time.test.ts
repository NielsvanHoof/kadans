import { describe, expect, it } from 'vitest';
import {
    formatDate,
    formatDateTime,
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

    it('supports a longer display style for pickers', () => {
        expect(
            formatDate('2025-03-15', 'en-GB', { dateStyle: 'long' }),
        ).toMatch(/15.*March.*2025/);
    });
});

describe('formatDateTime', () => {
    it('formats an absolute timestamp in UTC', () => {
        // Fixed instant — must not depend on the machine timezone.
        expect(formatDateTime('2025-03-15T14:30:45.000Z', 'en-GB')).toBe(
            '15/03/2025, 14:30:45',
        );
    });
});

describe('formatRelativeTime', () => {
    const now = new Date('2025-03-15T14:00:00.000Z');

    it('formats minutes ago', () => {
        expect(
            formatRelativeTime('2025-03-15T13:45:00.000Z', 'en', now),
        ).toBe('15 minutes ago');
    });

    it('formats hours ago', () => {
        expect(
            formatRelativeTime('2025-03-15T12:00:00.000Z', 'en', now),
        ).toBe('2 hours ago');
    });

    it('formats in the viewing locale', () => {
        expect(
            formatRelativeTime('2025-03-15T13:00:00.000Z', 'nl', now),
        ).toMatch(/uur/);
    });
});
