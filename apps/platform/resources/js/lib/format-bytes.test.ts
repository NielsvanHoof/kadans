import { describe, expect, it } from 'vitest';
import { formatBytes } from '@/lib/format-bytes';

const NBSP = ' ';

describe('formatBytes', () => {
    it('shows raw bytes below 1 KB', () => {
        expect(formatBytes(0)).toBe(`0${NBSP}B`);
        expect(formatBytes(512)).toBe(`512${NBSP}B`);
        expect(formatBytes(1023)).toBe(`1023${NBSP}B`);
    });

    it('switches to KB at exactly 1024 bytes', () => {
        expect(formatBytes(1024)).toBe(`1.0${NBSP}KB`);
    });

    it('switches to MB at exactly 1 MB', () => {
        expect(formatBytes(1024 * 1024)).toBe(`1.0${NBSP}MB`);
    });

    it('keeps one decimal place', () => {
        expect(formatBytes(1536)).toBe(`1.5${NBSP}KB`);
        expect(formatBytes(2_621_440)).toBe(`2.5${NBSP}MB`);
    });

    it('uses a non-breaking space so the unit never wraps alone', () => {
        expect(formatBytes(2048)).not.toContain(' ');
        expect(formatBytes(2048)).toContain(NBSP);
    });

    it('does not fall back to a smaller unit for very large files', () => {
        expect(formatBytes(1024 ** 3)).toBe(`1024.0${NBSP}MB`);
    });
});
