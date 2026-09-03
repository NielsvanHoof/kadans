import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { ArchivedClientSummary } from '@/features/clients/types';
import {
    mockInertiaPage,
    mockUseFormatters,
    mockUseTranslation,
} from '@/test/mocks';
import type { Paginated } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

vi.mock('@inertiajs/react', () => ({
    usePage: () =>
        mockInertiaPage({ current_firm: { name: 'Acme', slug: 'acme' } }),
    Form: ({
        children,
        action,
    }: {
        children: (props: { processing: boolean }) => ReactNode;
        action?: string;
    }) => <form action={action}>{children({ processing: false })}</form>,
}));

const { default: ArchivedClientsCard } =
    await import('@/features/clients/list/archived-clients-card');

function archived(
    id: number,
    name: string,
    dossiersCount: number,
): ArchivedClientSummary {
    return {
        id,
        name,
        email: `${name.toLowerCase()}@example.com`,
        dossiers_count: dossiersCount,
        archived_at: '2026-03-04T10:00:00+00:00',
    };
}

function paginate(
    rows: ArchivedClientSummary[],
): Paginated<ArchivedClientSummary> {
    return {
        data: rows,
        total: rows.length,
    } as Paginated<ArchivedClientSummary>;
}

const clients = paginate([archived(1, 'Ada', 2), archived(2, 'Grace', 1)]);

function bulkToolbar(): HTMLElement {
    const label =
        screen.queryByText('1 selected') ?? screen.getByText(/\d+ selected/);
    const toolbar = label.parentElement;

    if (!toolbar) {
        throw new Error('Expected the bulk toolbar around the selection label');
    }

    return toolbar;
}

describe('ArchivedClientsCard', () => {
    it('offers no restore control without the permission', () => {
        render(<ArchivedClientsCard clients={clients} canRestore={false} />);

        expect(
            screen.queryByRole('columnheader', { name: 'Actions' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Restore client' }),
        ).not.toBeInTheDocument();
        // No selection either — bulk restore is gated by the same prop, so an
        // ungated checkbox column would offer a bulk action that cannot fire.
        expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    });

    it('offers per-row restore with the permission', () => {
        render(<ArchivedClientsCard clients={clients} canRestore />);

        expect(
            screen.getAllByRole('button', { name: 'Restore client' }),
        ).toHaveLength(2);
    });

    it('says how many dossiers each archived client still holds', () => {
        render(<ArchivedClientsCard clients={clients} canRestore={false} />);

        // Retention is the reason this card exists: an archived client's dossiers
        // are kept, not deleted, until the retention window closes. The count is
        // what tells an operator how much is still there.
        expect(screen.getByText(/\d+ dossiers retained/)).toBeInTheDocument();
        expect(screen.getByText(/1 dossier retained/)).toBeInTheDocument();
    });

    it('carries the selected ids into bulk restore', async () => {
        const user = userEvent.setup();

        render(<ArchivedClientsCard clients={clients} canRestore />);

        const selectAll = screen.getAllByRole('checkbox')[0];

        if (!selectAll) {
            throw new Error('Expected a select-all checkbox');
        }

        await user.click(selectAll);
        await user.click(
            within(bulkToolbar()).getByRole('button', { name: 'Restore' }),
        );

        const dialog = await screen.findByRole('alertdialog');
        const ids = Array.from(
            dialog.querySelectorAll<HTMLInputElement>('input[name="ids[]"]'),
        ).map((input) => input.value);

        expect(ids).toEqual(['1', '2']);
    });

    it('explains the retention window when nothing is archived', () => {
        render(<ArchivedClientsCard clients={paginate([])} canRestore />);

        expect(screen.getByText('No archived clients.')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Clients you archive will remain available here during the retention period.',
            ),
        ).toBeInTheDocument();
    });
});
