import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { IndexQueryConfig } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        locale: 'en',
        availableLocales: [],
    }),
}));

// The global matchMedia stub in test/setup.ts always reports `matches: false`,
// so the toolbar would only ever render its desktop branch. Mock the hook
// itself through vi.hoisted so a single test can flip to the mobile tree.
const mobile = vi.hoisted(() => ({ value: false }));
vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => mobile.value,
}));

const setFilters = vi.fn();
const setSort = vi.fn();

vi.mock('@/hooks/use-index-query', () => ({
    useIndexQuery: () => ({
        filters: { status: 'open' },
        sort: null,
        setFilters,
        setSort,
    }),
}));

const { default: IndexQueryToolbar } =
    await import('@/components/data/index-query/index-query-toolbar');

const config: IndexQueryConfig = {
    filters: [
        { key: 'q', type: 'search', label: 'Search' },
        {
            key: 'status',
            type: 'select',
            label: 'Status',
            options: [
                { value: 'open', label: 'Open' },
                { value: 'closed', label: 'Closed' },
            ],
        },
    ],
    sorts: [
        { key: 'name', label: 'Name' },
        { key: '-created_at', label: 'Newest' },
    ],
    defaults: { sort: 'name' },
};

describe('IndexQueryToolbar', () => {
    it('flushes a search draft on blur instead of waiting for the debounce', async () => {
        const user = userEvent.setup();
        setFilters.mockClear();

        render(<IndexQueryToolbar config={config} />);

        const search = screen.getByRole('searchbox', { name: 'Search' });

        await user.click(search);
        await user.type(search, 'acme');
        // RAC Input blur + debounce flush (tab alone is flaky in jsdom).
        fireEvent.blur(search);

        await waitFor(() => {
            expect(setFilters).toHaveBeenCalledWith({
                status: 'open',
                q: 'acme',
            });
        });
    });

    it('clears a select filter when All is chosen', async () => {
        const user = userEvent.setup();
        setFilters.mockClear();

        render(<IndexQueryToolbar config={config} />);

        await user.click(screen.getByRole('button', { name: /Status/ }));
        await user.click(await screen.findByRole('option', { name: 'All' }));

        expect(setFilters).toHaveBeenCalledWith({});
    });

    it('shows a notice when search results were truncated', () => {
        render(
            <IndexQueryToolbar
                config={{ ...config, search_truncated: true }}
            />,
        );

        expect(
            screen.getByText(
                'Search results are limited to the first matches. Narrow your query to see more.',
            ),
        ).toBeInTheDocument();
    });

    it('caps search input at 255 characters', async () => {
        const user = userEvent.setup();
        setFilters.mockClear();

        render(<IndexQueryToolbar config={config} />);

        const search = screen.getByRole('searchbox', { name: 'Search' });

        expect(search).toHaveAttribute('maxLength', '255');

        await user.click(search);
        // Paste a string longer than the cap — onChange must clip it.
        fireEvent.change(search, {
            target: { value: 'a'.repeat(300) },
        });
        fireEvent.blur(search);

        await waitFor(() => {
            expect(setFilters).toHaveBeenCalledWith({
                status: 'open',
                q: 'a'.repeat(255),
            });
        });
        expect(search).toHaveValue('a'.repeat(255));
    });

    it('updates sort from the sort select', async () => {
        const user = userEvent.setup();
        setSort.mockClear();

        render(<IndexQueryToolbar config={config} />);

        await user.click(screen.getByRole('button', { name: /Sort/ }));
        await user.click(await screen.findByRole('option', { name: 'Newest' }));

        expect(setSort).toHaveBeenCalledWith('-created_at');
    });

    /*
     * Six controls stacked one per row measured 338px on a 375px screen and
     * pushed the first result to y=689 of an 812px viewport. Below md: only the
     * primary search stays out; the rest fold behind a disclosure that carries
     * a count, so an applied filter is never silently invisible.
     */
    describe('on mobile', () => {
        it('keeps search visible, folds the rest away, and counts what is applied', async () => {
            const user = userEvent.setup();
            mobile.value = true;

            try {
                render(<IndexQueryToolbar config={config} />);

                // Search is always reachable without a tap.
                expect(screen.getByLabelText('Search')).toBeInTheDocument();

                // `filters` is mocked as { status: 'open' }, so exactly one of
                // the folded controls is active.
                const trigger = screen.getByRole('button', {
                    name: /Filters/,
                });
                expect(trigger).toHaveTextContent('1');

                // The Status select is not reachable until the panel opens.
                expect(
                    screen.queryByRole('button', { name: /Status/ }),
                ).not.toBeInTheDocument();

                await user.click(trigger);

                expect(
                    await screen.findByRole('button', { name: /Status/ }),
                ).toBeInTheDocument();
            } finally {
                mobile.value = false;
            }
        });
    });
});
