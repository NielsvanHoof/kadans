import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { ClientSummary } from '@/features/clients/types';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';
import type { Paginated } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@inertiajs/react', () => ({
    usePage: () =>
        mockInertiaPage({ current_firm: { name: 'Acme', slug: 'acme' } }),
    Link: ({
        children,
        href,
    }: {
        children: ReactNode;
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url}>{children}</a>,
    Form: ({
        children,
        action,
    }: {
        children: (props: { processing: boolean }) => ReactNode;
        action?: string;
    }) => <form action={action}>{children({ processing: false })}</form>,
}));

const { default: ClientsListCard } =
    await import('@/features/clients/list/clients-list-card');

function client(
    id: number,
    name: string,
    dossiersCount: number,
): ClientSummary {
    return {
        id,
        name,
        email: `${name.toLowerCase()}@example.com`,
        dossiers_count: dossiersCount,
    } as ClientSummary;
}

function paginate(rows: ClientSummary[]): Paginated<ClientSummary> {
    return { data: rows, total: rows.length } as Paginated<ClientSummary>;
}

const clients = paginate([
    client(1, 'Ada', 2),
    client(2, 'Grace', 3),
    client(3, 'Linus', 0),
]);

/**
 * The bulk toolbar, scoped by its "N selected" label.
 *
 * Needed because the bulk archive button and every per-row archive button expose
 * the same accessible name, "Archive". A sighted user tells them apart by position;
 * a screen-reader user hears the same label N+1 times with nothing to distinguish
 * them. Worth fixing in the UI rather than only working around here.
 */
function bulkToolbar(): HTMLElement {
    const label =
        screen.queryByText('1 selected') ?? screen.getByText(':count selected');
    const toolbar = label.parentElement;

    if (!toolbar) {
        throw new Error('Expected the bulk toolbar around the selection label');
    }

    return toolbar;
}

describe('ClientsListCard', () => {
    it('offers no archive control to a viewer who cannot manage clients', () => {
        render(<ClientsListCard clients={clients} />);

        // Server-side policies are the real gate; this assertion is about not
        // showing a control that would 403. canManage defaults to false, so a
        // caller that forgets the prop fails closed rather than open.
        expect(
            screen.queryByRole('columnheader', { name: 'Actions' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Archive' }),
        ).not.toBeInTheDocument();
        expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    });

    it('offers per-row archive to a manager', () => {
        render(<ClientsListCard clients={clients} canManage />);

        expect(
            screen.getByRole('columnheader', { name: 'Actions' }),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Archive' })).toHaveLength(
            3,
        );
    });

    it('sums dossiers across the selection so the cascade warning is honest', async () => {
        const user = userEvent.setup();

        render(<ClientsListCard clients={clients} canManage />);

        const checkboxes = screen.getAllByRole('checkbox');
        const selectAll = checkboxes[0];

        if (!selectAll) {
            throw new Error('Expected a select-all checkbox');
        }

        await user.click(selectAll);

        // Ada 2 + Grace 3 + Linus 0 = 5. The bulk button only shows the plural
        // "their :count dossiers" copy when the sum is above zero, so a broken
        // reduce would silently drop the cascade warning entirely.
        //
        // Scoped to the toolbar because the bulk control and the per-row controls
        // share the accessible name "Archive" — see the note in this file's sibling
        // test for why that is worth knowing.
        await user.click(
            within(bulkToolbar()).getByRole('button', { name: 'Archive' }),
        );

        expect(
            await screen.findByText(
                'The selected clients and their :count dossiers will be archived. They will no longer appear in lists.',
            ),
        ).toBeInTheDocument();
    });

    it('drops the cascade warning when every selected client has no dossiers', async () => {
        const user = userEvent.setup();

        render(
            <ClientsListCard
                clients={paginate([client(3, 'Linus', 0)])}
                canManage
            />,
        );

        const checkboxes = screen.getAllByRole('checkbox');
        const selectAll = checkboxes[0];

        if (!selectAll) {
            throw new Error('Expected a select-all checkbox');
        }

        await user.click(selectAll);
        await user.click(
            within(bulkToolbar()).getByRole('button', { name: 'Archive' }),
        );

        expect(
            await screen.findByText(
                'The selected clients will be archived and will no longer appear in lists.',
            ),
        ).toBeInTheDocument();
    });

    it('invites the first client when the list is empty', () => {
        render(<ClientsListCard clients={paginate([])} canManage />);

        expect(
            screen.getByText('Add your first client to create a dossier.'),
        ).toBeInTheDocument();
    });

    it('counts in the singular for one client', () => {
        render(<ClientsListCard clients={paginate([client(1, 'Ada', 1)])} />);

        expect(screen.getByText('1 client')).toBeInTheDocument();
    });
});
