import { describe, expect, it } from 'vite-plus/test';
import { interpolateTranslation } from '@/lib/interpolate-translation';

describe('interpolateTranslation', () => {
    it('substitutes a shorter token without eating a longer one that shares its prefix', () => {
        expect(
            interpolateTranslation(':from–:to van :total weergegeven', {
                from: 1,
                to: 15,
                total: 15,
            }),
        ).toBe('1–15 van 15 weergegeven');
    });

    it('substitutes every occurrence of a repeated placeholder', () => {
        expect(
            interpolateTranslation(
                'You have :count items and :count pending.',
                { count: 3 },
            ),
        ).toBe('You have 3 items and 3 pending.');
    });

    it('leaves an unmatched placeholder literal', () => {
        expect(interpolateTranslation('Hello :name', { other: 'Ada' })).toBe(
            'Hello :name',
        );
    });
});
