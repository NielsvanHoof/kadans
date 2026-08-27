import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
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
        <form action={action} method={method} data-testid="restore-form">
            {children({ processing: false })}
        </form>
    ),
}));

const { default: RestoreClientButton } =
    await import('@/features/clients/list/restore-client-button');

describe('RestoreClientButton', () => {
    it('targets the specific client on the workspace-scoped restore route', async () => {
        const user = userEvent.setup();

        render(
            <RestoreClientButton
                clientId={42}
                clientName="Bakkerij de Vries"
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Restore client' }),
        );

        const dialog = await screen.findByRole('alertdialog');

        expect(within(dialog).getByTestId('restore-form')).toHaveAttribute(
            'action',
            '/workspaces/acme/clients/42/restore?_method=PATCH',
        );
    });

    it('promises dossiers come back, unlike archiving', async () => {
        const user = userEvent.setup();

        render(
            <RestoreClientButton
                clientId={42}
                clientName="Bakkerij de Vries"
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Restore client' }),
        );

        // Single-client restore does cascade to dossiers, where bulk restore does
        // not. The two copy strings differ for a real reason, so asserting this
        // one keeps a well-meaning "let's unify the wording" change honest.
        expect(
            await screen.findByText(
                'The client and their dossiers will appear in lists again.',
            ),
        ).toBeInTheDocument();
    });

    it('names the client in the confirmation title', async () => {
        const user = userEvent.setup();

        render(
            <RestoreClientButton
                clientId={42}
                clientName="Bakkerij de Vries"
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Restore client' }),
        );

        expect(
            await screen.findByRole('alertdialog', { name: 'Restore :name?' }),
        ).toBeInTheDocument();
    });
});
