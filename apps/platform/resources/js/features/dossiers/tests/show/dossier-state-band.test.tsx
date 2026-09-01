import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { Dossier } from '@/features/dossiers/types';
import type { AccessGrant } from '@/features/portal/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const { default: DossierStateBand } =
    await import('@/features/dossiers/show/dossier-state-band');

function dossier(overrides: Partial<Dossier> = {}): Dossier {
    return {
        id: 7,
        status: 'awaiting_client',
        document_requests: [],
        access_grants: [],
        ...overrides,
    } as Dossier;
}

const validGrant = { id: 1, is_valid: true } as AccessGrant;

function renderBand(overrides: Partial<Dossier> = {}) {
    const onSelectInvite = vi.fn();

    render(
        <DossierStateBand
            dossier={dossier(overrides)}
            onSelectInvite={onSelectInvite}
        />,
    );

    return { onSelectInvite };
}

describe('DossierStateBand', () => {
    it('places the three workflow stages on one strip', () => {
        renderBand();

        expect(screen.getByText('Build Form')).toBeInTheDocument();
        expect(screen.getByText('Send Invite')).toBeInTheDocument();
        expect(screen.getByText('Review')).toBeInTheDocument();
    });

    /**
     * Only the current stage carries its hint. A hint on a finished stage tells
     * the reader nothing they can act on, and hints on all three are what made
     * this rail bulky enough to need its own card.
     */
    it('hints only at the stage the dossier is actually on', () => {
        renderBand({
            document_requests: [{ id: 1, status: 'pending' }] as never,
        });

        expect(
            screen.getByText(
                'Send a portal link so the client can complete the form. Then continue to Review.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                'Build the client form by dragging fields into place.',
            ),
        ).not.toBeInTheDocument();
    });

    it('marks the stage in progress for assistive technology', () => {
        renderBand();

        expect(screen.getByText('Build Form').closest('a')).toHaveAttribute(
            'aria-current',
            'step',
        );
    });

    it('selects the access panel instead of navigating for the invite stage', async () => {
        const { onSelectInvite } = renderBand({
            document_requests: [{ id: 1, status: 'pending' }] as never,
        });

        await userEvent.click(
            screen.getByRole('button', { name: /Send Invite/ }),
        );

        expect(onSelectInvite).toHaveBeenCalledOnce();
    });

    it('blocks the review stage until the questionnaire has an item', () => {
        renderBand();

        expect(screen.getByText('Review').closest('a')).toBeNull();
    });

    it('opens the review route once items exist', () => {
        renderBand({
            document_requests: [{ id: 1, status: 'submitted' }] as never,
            access_grants: [validGrant],
        });

        expect(
            screen.getByText('Review').closest('a')?.getAttribute('href'),
        ).toContain('review');
    });
});
