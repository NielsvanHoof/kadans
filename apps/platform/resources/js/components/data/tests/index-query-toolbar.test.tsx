import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { IndexQueryConfig } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
        locale: 'en',
        availableLocales: [],
    }),
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

    it('updates sort from the sort select', async () => {
        const user = userEvent.setup();
        setSort.mockClear();

        render(<IndexQueryToolbar config={config} />);

        await user.click(screen.getByRole('button', { name: /Sort/ }));
        await user.click(await screen.findByRole('option', { name: 'Newest' }));

        expect(setSort).toHaveBeenCalledWith('-created_at');
    });
});
