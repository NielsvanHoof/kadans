import { describe, expect, it } from 'vitest';
import { formatDuration } from '@/lib/format-duration';

describe('formatDuration', () => {
    it('formats zero seconds', () => {
        expect(formatDuration(0)).toBe('0:00');
    });

    it('pads seconds below 10', () => {
        expect(formatDuration(5)).toBe('0:05');
    });

    it('formats minutes and seconds', () => {
        expect(formatDuration(75)).toBe('1:15');
        expect(formatDuration(600)).toBe('10:00');
    });

    it('rounds fractional seconds', () => {
        expect(formatDuration(59.6)).toBe('1:00');
        expect(formatDuration(1.2)).toBe('0:01');
    });

    it('clamps negative durations to zero', () => {
        expect(formatDuration(-5)).toBe('0:00');
    });
});
