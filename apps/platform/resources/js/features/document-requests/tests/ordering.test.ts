import { describe, expect, it } from 'vite-plus/test';
import { isOrderedBefore } from '@/features/document-requests/types';
import parityFixture from '../../../../../tests/fixtures/ordering-parity.json';

/**
 * Ordering parity (TypeScript side).
 *
 * The PHP counterpart is tests/Unit/DocumentRequestOrderingTest.php. Both read
 * tests/fixtures/ordering-parity.json, so the builder's notion of "earlier"
 * cannot drift from DocumentRequest::isOrderedBefore().
 */
describe('isOrderedBefore', () => {
    it('has parity cases to check', () => {
        expect(parityFixture.cases.length).toBeGreaterThan(0);
    });

    it.each(parityFixture.cases)('$name', ({ field, other, expected }) => {
        expect(isOrderedBefore(field, other)).toBe(expected);
    });

    it('is antisymmetric for distinct fields', () => {
        for (const { field, other } of parityFixture.cases) {
            if (
                field.id === other.id &&
                field.sort_order === other.sort_order
            ) {
                continue;
            }

            expect(isOrderedBefore(field, other)).toBe(
                !isOrderedBefore(other, field),
            );
        }
    });
});
