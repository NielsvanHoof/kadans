import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children: (bag: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
    }) => children({ errors: {}, processing: false }),
}));

const { default: DossierActionsMenu } =
    await import('@/features/dossiers/show/dossier-actions-menu');

function renderMenu(
    capabilities: {
        canEditDetails?: boolean;
        canManage?: boolean;
        canRollOver?: boolean;
    } = {},
) {
    return render(
        <DossierActionsMenu
            dossier={{
                id: 7,
                title: 'Jaarrekening 2025',
                period_year: 2025,
                period_label: 'Jaarrekening 2025',
                due_date: '2026-05-31',
            }}
            canEditDetails={capabilities.canEditDetails ?? true}
            canManage={capabilities.canManage ?? true}
            canRollOver={capabilities.canRollOver ?? true}
        />,
    );
}

describe('DossierActionsMenu — roll over', () => {
    it('offers the roll-over action to a member who holds the permission', async () => {
        const user = userEvent.setup();
        renderMenu();

        await user.click(screen.getByRole('button', { name: 'Actions' }));

        expect(
            screen.getByRole('menuitem', { name: 'Start next period' }),
        ).toBeInTheDocument();
    });

    it('hides the roll-over action from a member without the permission', async () => {
        const user = userEvent.setup();
        renderMenu({ canRollOver: false });

        await user.click(screen.getByRole('button', { name: 'Actions' }));

        expect(
            screen.queryByRole('menuitem', { name: 'Start next period' }),
        ).not.toBeInTheDocument();
    });

    /*
     * The menu renders nothing at all when a member holds none of its three
     * abilities — roll-over included, or a read-only member would be shown an
     * empty trigger.
     */
    it('renders no trigger when the member can do none of the three', () => {
        renderMenu({
            canEditDetails: false,
            canManage: false,
            canRollOver: false,
        });

        expect(
            screen.queryByRole('button', { name: 'Actions' }),
        ).not.toBeInTheDocument();
    });

    it('opens the roll-over dialog from the menu item', async () => {
        const user = userEvent.setup();
        renderMenu();

        await user.click(screen.getByRole('button', { name: 'Actions' }));
        await user.click(
            screen.getByRole('menuitem', { name: 'Start next period' }),
        );

        expect(
            screen.getByRole('heading', { name: 'Start the next period' }),
        ).toBeInTheDocument();
    });
});
