import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';

// Built at runtime so TranslationCallSiteScanner does not harvest these as
// live keys from the test file (it only matches literal t call sites).
const helloKey = ['Hello', ':name'].join(' ');
const repeatedKey = [
    'You have',
    ':count',
    'items and',
    ':count',
    'pending.',
].join(' ');
const rangeKey = ['Showing :from–:to of', ':total'].join(' ');
const missingKey = ['Definitely', 'missing', 'test-only', 'key'].join(' ');

const pageProps = {
    locale: 'en',
    available_locales: [],
    translations: {
        [helloKey]: 'Hallo :name',
        [repeatedKey]: 'You have :count items and :count pending.',
        [rangeKey]: ':from–:to van :total weergegeven',
    } as Record<string, string>,
};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps, url: '/' }),
}));

const { useTranslation } = await import('@/hooks/use-translation');

describe('useTranslation', () => {
    beforeEach(() => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('substitutes a single placeholder', () => {
        const { result } = renderHook(() => useTranslation());

        expect(result.current.t(helloKey, { name: 'Ada' })).toBe('Hallo Ada');
    });

    it('substitutes every occurrence of a repeated placeholder', () => {
        const { result } = renderHook(() => useTranslation());

        expect(result.current.t(repeatedKey, { count: 3 })).toBe(
            'You have 3 items and 3 pending.',
        );
    });

    it('does not treat :to as a prefix of :total', () => {
        const { result } = renderHook(() => useTranslation());

        expect(result.current.t(rangeKey, { from: 1, to: 15, total: 15 })).toBe(
            '1–15 van 15 weergegeven',
        );
    });

    it('leaves an unmatched placeholder literal and reports a missing key', () => {
        const { result } = renderHook(() => useTranslation());

        expect(result.current.t(missingKey, { token: 'x' })).toBe(missingKey);
        expect(console.error).toHaveBeenCalledWith(
            `Missing translation key: ${missingKey}`,
        );
    });
});
