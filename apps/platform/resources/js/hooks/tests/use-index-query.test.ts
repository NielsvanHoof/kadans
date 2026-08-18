import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mockInertiaPage } from '@/test/mocks';

const routerGet = vi.fn();
const routerOn = vi.fn(() => () => {});
let pageProps: Record<string, unknown> = {};
let pageUrl = '/workspaces/acme/dossiers';

vi.mock('@inertiajs/react', () => ({
    router: {
        get: routerGet,
        on: routerOn,
    },
    usePage: () => mockInertiaPage(pageProps, pageUrl),
}));

const { useIndexQuery } = await import('@/hooks/use-index-query');

/** The (path, data, options) triple handed to router.get. */
function lastVisit() {
    const call = routerGet.mock.calls.at(-1);

    return {
        path: call?.[0] as string,
        data: call?.[1] as { filter?: Record<string, string>; sort?: string },
        options: call?.[2] as Record<string, unknown>,
    };
}

beforeEach(() => {
    routerGet.mockReset();
    pageProps = {};
    pageUrl = '/workspaces/acme/dossiers';
});

describe('useIndexQuery', () => {
    describe('reading controller state', () => {
        it('exposes filters and sort from page props', () => {
            pageProps = {
                filters: { q: 'acme', status: 'draft' },
                sort: '-updated_at',
            };

            const { result } = renderHook(() => useIndexQuery());

            expect(result.current.filters).toEqual({
                q: 'acme',
                status: 'draft',
            });
            expect(result.current.sort).toBe('-updated_at');
        });

        it('tolerates missing or non-object filter props', () => {
            pageProps = { filters: null, sort: 42 };

            const { result } = renderHook(() => useIndexQuery());

            expect(result.current.filters).toEqual({});
            // A non-string sort is not a sort.
            expect(result.current.sort).toBeNull();
        });

        it('coerces non-string filter values', () => {
            pageProps = { filters: { page_size: 25 } };

            const { result } = renderHook(() => useIndexQuery());

            expect(result.current.filters).toEqual({ page_size: '25' });
        });
    });

    describe('visiting', () => {
        it('drops the existing query string so filtering restarts at page 1', () => {
            pageUrl = '/workspaces/acme/dossiers?page=4&filter[q]=old';

            const { result } = renderHook(() => useIndexQuery());
            act(() => result.current.setFilters({ q: 'new' }));

            const { path, data } = lastVisit();
            expect(path).toBe('/workspaces/acme/dossiers');
            expect(data).toEqual({ filter: { q: 'new' } });
            // No page param survives the filter change.
            expect(Object.keys(data)).not.toContain('page');
        });

        it('omits empty filter values rather than sending blanks', () => {
            const { result } = renderHook(() => useIndexQuery());

            act(() =>
                result.current.setFilters({
                    q: '',
                    status: 'draft',
                    owner: null,
                    tag: undefined,
                }),
            );

            expect(lastVisit().data).toEqual({ filter: { status: 'draft' } });
        });

        it('omits the filter bag entirely when nothing is set', () => {
            const { result } = renderHook(() => useIndexQuery());

            act(() => result.current.setFilters({ q: '' }));

            expect(lastVisit().data).toEqual({});
        });

        it('keeps current filters when only the sort changes', () => {
            pageProps = { filters: { q: 'acme' }, sort: 'title' };

            const { result } = renderHook(() => useIndexQuery());
            act(() => result.current.setSort('-created_at'));

            expect(lastVisit().data).toEqual({
                filter: { q: 'acme' },
                sort: '-created_at',
            });
        });

        it('keeps current sort when only filters change', () => {
            pageProps = { filters: {}, sort: '-updated_at' };

            const { result } = renderHook(() => useIndexQuery());
            act(() => result.current.setFilters({ q: 'acme' }));

            expect(lastVisit().data).toEqual({
                filter: { q: 'acme' },
                sort: '-updated_at',
            });
        });

        it('treats clearing the sort as omitting it', () => {
            pageProps = { filters: {}, sort: 'title' };

            const { result } = renderHook(() => useIndexQuery());
            act(() => result.current.setSort(null));

            expect(lastVisit().data).toEqual({});
        });

        it('preserves state and scroll, and replaces history', () => {
            const { result } = renderHook(() => useIndexQuery());
            act(() => result.current.setFilters({ q: 'acme' }));

            const { options } = lastVisit();
            expect(options).toMatchObject({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        });

        it('passes partial-reload keys through when given', () => {
            const { result } = renderHook(() =>
                useIndexQuery({ only: ['dossiers'] }),
            );
            act(() => result.current.setFilters({ q: 'acme' }));

            expect(lastVisit().options.only).toEqual(['dossiers']);
        });

        it('omits `only` when the list is empty', () => {
            const { result } = renderHook(() => useIndexQuery({ only: [] }));
            act(() => result.current.setFilters({ q: 'acme' }));

            expect(lastVisit().options).not.toHaveProperty('only');
        });
    });
});
