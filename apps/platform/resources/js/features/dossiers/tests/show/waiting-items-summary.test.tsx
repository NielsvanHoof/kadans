import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DocumentRequest } from '@/features/document-requests/types';
import type { Dossier, DossierStatus } from '@/features/dossiers/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

const useDossierCapabilities = vi.fn();

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@/features/dossiers/permissions/dossier-permissions-context', () => ({
    useDossierCapabilities,
}));

// The header owns the reminder dialog, which drags in Inertia forms and
// Wayfinder actions. It has its own test; here it only has to exist.
vi.mock('@/features/dossiers/show/waiting-chase-header', () => ({
    default: ({ openCount }: { openCount: number }) => (
        <div data-testid="chase-header">{openCount}</div>
    ),
}));

const { default: WaitingItemsSummary } =
    await import('@/features/dossiers/show/waiting-items-summary');

function withDossierStatus(dossierStatus: DossierStatus): void {
    useDossierCapabilities.mockReturnValue({ dossierStatus });
}

beforeEach(() => {
    withDossierStatus('in_review');
});

function request(overrides: Partial<DocumentRequest>): DocumentRequest {
    return {
        id: 1,
        title: 'Item',
        type: 'file',
        status: 'pending',
        instructions: null,
        configuration: null,
        rejection_reason: null,
        reviewed_at: null,
        reviewed_by_name: null,
        is_visible: true,
        is_applicable: true,
        ...overrides,
    } as DocumentRequest;
}

function dossier(documentRequests: DocumentRequest[]): Dossier {
    return {
        id: 7,
        document_requests: documentRequests,
        access_grants: [],
        last_client_message_sent_at: null,
        has_outstanding_client_items: documentRequests.length > 0,
    } as unknown as Dossier;
}

function renderSummary(documentRequests: DocumentRequest[]) {
    return render(
        <WaitingItemsSummary
            dossier={dossier(documentRequests)}
            onSelectAccess={vi.fn()}
        />,
    );
}

describe('WaitingItemsSummary', () => {
    it('shows the empty state when nothing is pending or rejected', () => {
        renderSummary([request({ id: 1, status: 'accepted' })]);

        expect(
            screen.getByText(
                'Nothing waiting — switch to Review when uploads arrive.',
            ),
        ).toBeInTheDocument();
    });

    /**
     * This panel is the page's default content, so its empty state has to point
     * somewhere real — a closed dossier has no Review left to switch to.
     */
    it('points a completed dossier at nothing instead of at Review', () => {
        withDossierStatus('completed');

        renderSummary([request({ id: 1, status: 'accepted' })]);

        expect(
            screen.getByText(
                'Every item was approved and the dossier is closed.',
            ),
        ).toBeInTheDocument();
    });

    it('lists pending and rejected items but excludes accepted/submitted ones', () => {
        renderSummary([
            request({ id: 1, title: 'Bank statement', status: 'pending' }),
            request({ id: 2, title: 'ID document', status: 'rejected' }),
            request({ id: 3, title: 'Signed contract', status: 'accepted' }),
            request({ id: 4, title: 'Proof of address', status: 'submitted' }),
        ]);

        expect(screen.getByText('Bank statement')).toBeInTheDocument();
        expect(screen.getByText('ID document')).toBeInTheDocument();
        expect(screen.queryByText('Signed contract')).not.toBeInTheDocument();
        expect(screen.queryByText('Proof of address')).not.toBeInTheDocument();
    });

    /**
     * Status is stated once per group rather than once per row, so each group
     * has to be a labelled region — that heading is what a screen reader
     * announces for the rows beneath it.
     */
    it('files each item under the group that names its status', () => {
        renderSummary([
            request({ id: 1, title: 'Bank statement', status: 'pending' }),
            request({ id: 2, title: 'ID document', status: 'rejected' }),
        ]);

        const returned = screen.getByRole('region', {
            name: 'Returned for correction',
        });
        const waiting = screen.getByRole('region', {
            name: 'Waiting on client',
        });

        expect(within(returned).getByText('ID document')).toBeInTheDocument();
        expect(within(waiting).getByText('Bank statement')).toBeInTheDocument();
    });

    /**
     * The reason is mandatory server-side and the person chasing tomorrow is
     * rarely the person who rejected today.
     */
    it('carries the rejection reason and who returned it', () => {
        renderSummary([
            request({
                id: 1,
                title: 'ID document',
                status: 'rejected',
                rejection_reason: 'Photo is cut off.',
                reviewed_by_name: 'Sander',
                reviewed_at: '2026-08-20T09:00:00+00:00',
            }),
        ]);

        expect(screen.getByText('Photo is cut off.')).toBeInTheDocument();
        expect(
            screen.getByText('Returned by :name on :date'),
        ).toBeInTheDocument();
    });

    it('marks an item optional only when it is not required', () => {
        renderSummary([
            request({
                id: 1,
                title: 'Turnover',
                configuration: { is_required: false },
            }),
            request({
                id: 2,
                title: 'Bank statement',
                configuration: { is_required: true },
            }),
        ]);

        expect(screen.getAllByText('Optional')).toHaveLength(1);
    });

    /**
     * A field on an untriggered branch is not work the client can do, so it is
     * excluded from the count the tab strip shows — but it still has to be
     * inspectable, or "nine fields, four rows" becomes an unanswerable question.
     */
    it('folds items the client is not being asked for into their own group', async () => {
        const user = userEvent.setup();

        renderSummary([
            request({ id: 1, title: 'Bank statement' }),
            request({
                id: 2,
                title: 'Foreign income detail',
                is_applicable: false,
                is_visible: false,
            }),
        ]);

        expect(screen.getByTestId('chase-header')).toHaveTextContent('1');
        // React Aria keeps the panel mounted and hides it, so visibility — not
        // presence — is what tells us the group is folded away.
        expect(screen.getByText('Foreign income detail')).not.toBeVisible();

        await user.click(
            screen.getByRole('button', {
                name: 'Not currently asked (:count)',
            }),
        );

        expect(screen.getByText('Foreign income detail')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Hidden by a visibility rule. Any past answer is kept for audit.',
            ),
        ).toBeInTheDocument();
    });

    it('still offers the not-asked group when nothing is waiting', () => {
        renderSummary([
            request({ id: 1, status: 'accepted' }),
            request({ id: 2, title: 'Foreign income', is_applicable: false }),
        ]);

        expect(
            screen.getByText(
                'Nothing waiting — switch to Review when uploads arrive.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Not currently asked (:count)',
            }),
        ).toBeInTheDocument();
    });
});
