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
        <form action={action} method={method} data-testid="archive-form">
            {children({ processing: false })}
        </form>
    ),
}));

const { default: ArchiveClientButton } =
    await import('@/features/clients/manage/archive-client-button');

async function openDialog(dossiersCount: number) {
    const user = userEvent.setup();

    render(
        <ArchiveClientButton
            clientId={42}
            clientName="Bakkerij de Vries"
            dossiersCount={dossiersCount}
        />,
    );

    await user.click(screen.getByRole('button', { name: 'Archive' }));

    return user;
}

describe('ArchiveClientButton', () => {
    it('targets the specific client on the workspace-scoped route', async () => {
        await openDialog(0);

        // Route model binding means the client id is in the path, not the body —
        // so the URL is the whole payload here, and a wrong id archives a
        // different firm's-worth of work.
        //
        // The ?_method=DELETE is asserted rather than trimmed: the route is
        // DELETE-only, browsers post forms, and losing the spoof turns archive
        // into a 405 that only shows up in a real browser.
        const form = await screen.findByTestId('archive-form');

        expect(form).toHaveAttribute(
            'action',
            '/workspaces/acme/clients/42?_method=DELETE',
        );
        expect(form).toHaveAttribute('method', 'post');
    });

    it('warns that dossiers cascade when the client has any', async () => {
        await openDialog(3);

        expect(
            await screen.findByText(
                'This client and their 3 dossiers will be archived. They will no longer appear in lists.',
            ),
        ).toBeInTheDocument();
    });

    it('omits the dossier warning when the client has none', async () => {
        await openDialog(0);

        expect(
            await screen.findByText(
                'This client will be archived and will no longer appear in lists.',
            ),
        ).toBeInTheDocument();
    });

    it('names the client in the confirmation title', async () => {
        await openDialog(0);

        expect(
            await screen.findByRole('alertdialog', {
                name: 'Archive Bakkerij de Vries?',
            }),
        ).toBeInTheDocument();
    });
});
