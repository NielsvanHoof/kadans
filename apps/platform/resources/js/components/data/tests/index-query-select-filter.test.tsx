import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import IndexQuerySelectFilter from '@/components/data/index-query/index-query-select-filter';
import type { IndexQueryFilterOption } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (
            key: string,
            replacements: Record<string, string | number> = {},
        ) => {
            let translation = key;

            for (const [placeholder, value] of Object.entries(replacements)) {
                translation = translation.replace(
                    `:${placeholder}`,
                    String(value),
                );
            }

            return translation;
        },
        locale: 'en',
        availableLocales: [],
    }),
}));

/** Twelve options so the filter crosses the searchable threshold. */
function buildManyOptions(): IndexQueryFilterOption[] {
    return Array.from({ length: 12 }, (_, index) => {
        const n = index + 1;

        return {
            value: `event-${n}`,
            label: n <= 6 ? `Alpha option ${n}` : `Beta option ${n}`,
            group: n <= 6 ? 'Alpha' : 'Beta',
        };
    });
}

describe('IndexQuerySelectFilter', () => {
    it('keeps short option lists as a plain select without a search field', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(
            <IndexQuerySelectFilter
                label="Status"
                selectedKey="open"
                options={[
                    { value: 'open', label: 'Open' },
                    { value: 'closed', label: 'Closed' },
                ]}
                onSelectionChange={onSelectionChange}
            />,
        );

        await user.click(screen.getByRole('button', { name: /Status|Open/ }));

        expect(
            screen.queryByRole('searchbox', { name: /Search Status/ }),
        ).not.toBeInTheDocument();
        expect(
            await screen.findByRole('option', { name: 'Closed' }),
        ).toBeInTheDocument();
    });

    it('shows a combobox typeahead, group labels, and an empty state for long lists', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(
            <IndexQuerySelectFilter
                label="Event"
                selectedKey="__all__"
                options={buildManyOptions()}
                onSelectionChange={onSelectionChange}
            />,
        );

        // Long lists become a real combobox: the input *is* the typeahead,
        // so there is no separate search field wedged into the popover.
        const combobox = screen.getByRole('combobox', { name: 'Event' });
        await user.click(combobox);
        await user.keyboard('{ArrowDown}');

        const listbox = await screen.findByRole('listbox');
        expect(
            await within(listbox).findByRole('option', {
                name: 'Alpha option 1',
            }),
        ).toBeInTheDocument();
        expect(
            within(listbox).getByText('Alpha', {
                selector: '[data-slot=combobox-label]',
            }),
        ).toBeInTheDocument();
        expect(
            within(listbox).getByText('Beta', {
                selector: '[data-slot=combobox-label]',
            }),
        ).toBeInTheDocument();

        await user.type(combobox, 'Alpha option 1');
        expect(
            await within(listbox).findByRole('option', {
                name: 'Alpha option 1',
            }),
        ).toBeInTheDocument();
        expect(
            within(listbox).queryByRole('option', { name: 'Beta option 12' }),
        ).not.toBeInTheDocument();

        await user.clear(combobox);
        await user.type(combobox, 'zzzz-no-match');

        expect(
            await screen.findByText('No matching options.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Alpha option 1' }),
        ).not.toBeInTheDocument();
    });

    it('posts the option id, not its label, when a long-list option is picked', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(
            <IndexQuerySelectFilter
                label="Event"
                selectedKey="__all__"
                options={buildManyOptions()}
                onSelectionChange={onSelectionChange}
            />,
        );

        const combobox = screen.getByRole('combobox', { name: 'Event' });
        await user.click(combobox);
        await user.keyboard('{ArrowDown}');
        await user.clear(combobox);
        await user.type(combobox, 'Beta option 7');

        await user.click(
            await screen.findByRole('option', { name: 'Beta option 7' }),
        );

        // RAC collections key off `id`; a missing id silently falls back to the
        // rendered text and the filter would post "Beta option 7" as the value.
        expect(onSelectionChange).toHaveBeenCalledWith('event-7');
    });
});
