import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@inertiajs/react', () => ({
    usePage: () =>
        mockInertiaPage({ current_firm: { name: 'Acme', slug: 'acme' } }),
    Form: ({
        children,
        action,
        method,
    }: {
        children: (props: { processing: boolean }) => ReactNode;
        action?: string;
        method?: string;
    }) => (
        <form action={action} method={method} data-testid="bulk-restore-form">
            {children({ processing: false })}
        </form>
    ),
}));

const { default: BulkRestoreClientsButton } =
    await import('@/features/clients/list/bulk-restore-clients-button');

async function openDialog(ids: number[]) {
    const user = userEvent.setup();

    render(<BulkRestoreClientsButton selectedIds={ids} />);

    await user.click(screen.getByRole('button', { name: 'Restore' }));

    return user;
}

describe('BulkRestoreClientsButton', () => {
    it('renders nothing when no clients are selected', () => {
        const { container } = render(
            <BulkRestoreClientsButton selectedIds={[]} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('carries one hidden id per selected client', async () => {
        await openDialog([3, 9]);

        const form = await screen.findByTestId('bulk-restore-form');
        const ids = Array.from(
            form.querySelectorAll<HTMLInputElement>('input[name="ids[]"]'),
        ).map((input) => input.value);

        expect(ids).toEqual(['3', '9']);
    });

    it('posts to the workspace-scoped bulk restore route', async () => {
        await openDialog([3]);

        expect(await screen.findByTestId('bulk-restore-form')).toHaveAttribute(
            'action',
            '/workspaces/acme/clients/bulk-restore',
        );
    });

    it('speaks in the singular for one client', async () => {
        await openDialog([3]);

        expect(
            await screen.findByRole('alertdialog', {
                name: 'Restore 1 client?',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Restore client' }),
        ).toBeInTheDocument();
    });

    it('speaks in the plural for several clients', async () => {
        await openDialog([3, 9]);

        expect(
            await screen.findByRole('alertdialog', {
                name: 'Restore :count clients?',
            }),
        ).toBeInTheDocument();
    });

    it('tells the user their dossiers stay archived', async () => {
        await openDialog([3, 9]);

        // Restore is deliberately not symmetric with archive: archiving a client
        // cascades to dossiers, restoring one does not bring them back. Saying so
        // is the difference between a surprised user and an informed one.
        expect(
            await screen.findByText(
                'The selected clients will appear in lists again. Their dossiers stay archived until restored separately.',
            ),
        ).toBeInTheDocument();
    });
});
