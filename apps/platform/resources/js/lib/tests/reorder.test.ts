import { describe, expect, it } from 'vite-plus/test';
import { applyIdOrder, reorderIds, sameIdOrder } from '@/lib/reorder';

/**
 * Drives drag-and-drop in the questionnaire builder and template editor, where a
 * wrong result silently reorders a client's form.
 */
describe('reorderIds', () => {
    it('moves an id forward', () => {
        expect(reorderIds([1, 2, 3, 4], 1, 3)).toEqual([2, 3, 1, 4]);
    });

    it('moves an id backward', () => {
        expect(reorderIds([1, 2, 3, 4], 4, 2)).toEqual([1, 4, 2, 3]);
    });

    it('returns the input untouched when the ids are the same', () => {
        const ids = [1, 2, 3];
        expect(reorderIds(ids, 2, 2)).toBe(ids);
    });

    it('returns the input untouched when an id is unknown', () => {
        const ids = [1, 2, 3];
        expect(reorderIds(ids, 99, 2)).toBe(ids);
        expect(reorderIds(ids, 1, 99)).toBe(ids);
    });

    it('does not mutate the original list', () => {
        const ids = [1, 2, 3];
        reorderIds(ids, 1, 3);
        expect(ids).toEqual([1, 2, 3]);
    });

    it('preserves length and membership', () => {
        const result = reorderIds([5, 6, 7, 8], 8, 5);
        expect(result).toHaveLength(4);
        expect([...result].sort((a, b) => a - b)).toEqual([5, 6, 7, 8]);
    });

    it('handles negative draft ids alongside real ones', () => {
        expect(reorderIds([-1, 2, -3], -3, -1)).toEqual([-3, -1, 2]);
    });
});

describe('sameIdOrder', () => {
    it('is true for identical sequences', () => {
        expect(sameIdOrder([1, 2, 3], [1, 2, 3])).toBe(true);
    });

    it('is false when the order differs', () => {
        expect(sameIdOrder([1, 2, 3], [1, 3, 2])).toBe(false);
    });

    it('is false when the length differs', () => {
        expect(sameIdOrder([1, 2], [1, 2, 3])).toBe(false);
    });

    it('is true for two empty lists', () => {
        expect(sameIdOrder([], [])).toBe(true);
    });
});

describe('applyIdOrder', () => {
    const items = [
        { id: 1, title: 'a' },
        { id: 2, title: 'b' },
        { id: 3, title: 'c' },
    ];

    it('reorders items to match the id sequence', () => {
        expect(applyIdOrder(items, [3, 1, 2]).map((i) => i.id)).toEqual([
            3, 1, 2,
        ]);
    });

    it('drops ids with no matching item rather than emitting holes', () => {
        expect(applyIdOrder(items, [3, 99, 1]).map((i) => i.id)).toEqual([
            3, 1,
        ]);
    });

    it('drops items missing from the id sequence', () => {
        expect(applyIdOrder(items, [2]).map((i) => i.id)).toEqual([2]);
    });

    it('returns an empty list for an empty order', () => {
        expect(applyIdOrder(items, [])).toEqual([]);
    });
});
