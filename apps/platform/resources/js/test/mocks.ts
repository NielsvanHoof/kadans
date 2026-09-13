/**
 * Shared vi.mock() factories for patterns repeated across test files.
 *
 * These are meant to be called *inside* a `vi.mock(...)` factory, e.g.:
 *
 *   vi.mock('@/hooks/use-translation', () => mockUseTranslation());
 *   vi.mock('@inertiajs/react', () => ({ usePage: () => mockInertiaPage(props) }));
 *
 * Safe to reference imported bindings inside a vi.mock factory: the factory
 * only runs lazily when the mocked module is first imported, by which point
 * this module's imports have already been evaluated.
 */

import {
    formatDate,
    formatDateTime,
    formatDateTimeWithZone,
    formatRelativeTime,
} from '@/lib/format-date-time';
import { interpolateTranslation } from '@/lib/interpolate-translation';

export function mockUseTranslation() {
    return {
        useTranslation: () => ({
            t: (
                key: string,
                replacements: Record<string, string | number> = {},
            ): string => interpolateTranslation(key, replacements),
            locale: 'en',
            availableLocales: [],
        }),
    };
}

/**
 * Formatters bound to a fixed locale and zone.
 *
 * The real hook reads `usePage()`, so components that format dates need either an
 * Inertia page context or this mock. UTC keeps expected strings stable; pass a
 * zone when a test is specifically about timezone rendering.
 */
export function mockUseFormatters(locale = 'en', timeZone = 'UTC') {
    return {
        useFormatters: () => ({
            formatDateTime: (iso: string) =>
                formatDateTime(iso, locale, timeZone),
            formatDateTimeWithZone: (iso: string) =>
                formatDateTimeWithZone(iso, locale, timeZone),
            formatDate: (iso: string, options?: Intl.DateTimeFormatOptions) =>
                formatDate(iso, locale, options),
            formatRelativeTime: (iso: string, now?: Date) =>
                formatRelativeTime(iso, locale, now),
        }),
    };
}

export function mockInertiaPage<Props extends Record<string, unknown>>(
    props: Props = {} as Props,
    url = '/',
): { props: Props; url: string } {
    return { props, url };
}
