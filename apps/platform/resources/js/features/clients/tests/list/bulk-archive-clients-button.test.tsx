import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

/*
 * `usePage` is mocked rather than `useCurrentWorkspace`, so the real hook runs and
 * the real Wayfinder action builds the real URL. Mocking the hook would have let a
 * bulk archive post to the wrong workspace without a test noticing.
 *
 * The Form mock allowlists `action`/`method` instead of spreading: Inertia-only
 * props (onSuccess, preserveState, …) on a DOM <form> produce React warnings.
 */
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
        <form action={action} method={method} data-testid="bulk-archive-form">
            {children({ processing: false })}
        </form>
    ),
}));

const { default: BulkArchiveClientsButton } =
    await import('@/features/clients/list/bulk-archive-clients-button');

async function openDialog(ids: number[], totalDossiersCount = 0) {
    const user = userEvent.setup();

    render(
        <BulkArchiveClientsButton
            selectedIds={ids}
            totalDossiersCount={totalDossiersCount}
        />,
    );

    await user.click(screen.getByRole('button', { name: 'Archive' }));

    return user;
}

describe('BulkArchiveClientsButton', () => {
    it('renders nothing when no clients are selected', () => {
        const { container } = render(
            <BulkArchiveClientsButton
                selectedIds={[]}
                totalDossiersCount={0}
            />,
        );

        // The guard matters more than it looks: without it the toolbar offers a
        // destructive confirmation whose payload would archive nothing, and the
        // user learns the selection was lost only after confirming.
        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('carries one hidden id per selected client', async () => {
        await openDialog([7, 12, 30]);

        const form = await screen.findByTestId('bulk-archive-form');
        const ids = Array.from(
            form.querySelectorAll<HTMLInputElement>('input[name="ids[]"]'),
        ).map((input) => input.value);

        // This array *is* the destructive payload. A dropped or duplicated entry
        // archives the wrong set of clients, and nothing else in the dialog would
        // look wrong while it happened.
        expect(ids).toEqual(['7', '12', '30']);
    });

    it('posts to the workspace-scoped bulk archive route', async () => {
        await openDialog([7]);

        const form = await screen.findByTestId('bulk-archive-form');

        expect(form).toHaveAttribute(
            'action',
            '/workspaces/acme/clients/bulk-archive',
        );
        expect(form).toHaveAttribute('method', 'post');
    });

    it('speaks in the singular for one client', async () => {
        await openDialog([7]);

        expect(
            await screen.findByRole('alertdialog', {
                name: 'Archive 1 client?',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Archive client' }),
        ).toBeInTheDocument();
    });

    it('speaks in the plural for several clients', async () => {
        await openDialog([7, 12]);

        expect(
            await screen.findByRole('alertdialog', {
                name: 'Archive 2 clients?',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Archive 2 clients' }),
        ).toBeInTheDocument();
    });

    it('warns that dossiers cascade when the selection has any', async () => {
        await openDialog([7, 12], 5);

        // Archiving a client cascades to their live dossiers. The cascade warning
        // is the only place the user is told that before confirming.
        expect(
            await screen.findByText(
                'The selected clients and their 5 dossiers will be archived. They will no longer appear in lists.',
            ),
        ).toBeInTheDocument();
    });

    it('omits the dossier warning when the selection has none', async () => {
        await openDialog([7, 12], 0);

        expect(
            await screen.findByText(
                'The selected clients will be archived and will no longer appear in lists.',
            ),
        ).toBeInTheDocument();
    });
});
