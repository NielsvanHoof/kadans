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

export function mockUseTranslation() {
    return {
        useTranslation: () => ({
            t: (key: string) => key,
            locale: 'en',
            availableLocales: [],
        }),
    };
}

export function mockInertiaPage<Props extends Record<string, unknown>>(
    props: Props = {} as Props,
    url = '/',
): { props: Props; url: string } {
    return { props, url };
}
