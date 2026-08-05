import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { DocumentRequest } from '@/features/document-requests/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: WaitingItemsSummary } =
    await import('@/features/dossiers/show/waiting-items-summary');

function request(overrides: Partial<DocumentRequest>): DocumentRequest {
    return {
        id: 1,
        title: 'Item',
        status: 'pending',
        ...overrides,
    } as DocumentRequest;
}

describe('WaitingItemsSummary', () => {
    it('shows the empty state when nothing is pending or rejected', () => {
        render(
            <WaitingItemsSummary
                documentRequests={[request({ id: 1, status: 'accepted' })]}
            />,
        );

        expect(
            screen.getByText(
                'Nothing waiting — switch to Review when uploads arrive.',
            ),
        ).toBeInTheDocument();
    });

    it('lists pending and rejected items but excludes accepted/submitted ones', () => {
        render(
            <WaitingItemsSummary
                documentRequests={[
                    request({
                        id: 1,
                        title: 'Bank statement',
                        status: 'pending',
                    }),
                    request({
                        id: 2,
                        title: 'ID document',
                        status: 'rejected',
                    }),
                    request({
                        id: 3,
                        title: 'Signed contract',
                        status: 'accepted',
                    }),
                    request({
                        id: 4,
                        title: 'Proof of address',
                        status: 'submitted',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('Bank statement')).toBeInTheDocument();
        expect(screen.getByText('ID document')).toBeInTheDocument();
        expect(screen.queryByText('Signed contract')).not.toBeInTheDocument();
        expect(screen.queryByText('Proof of address')).not.toBeInTheDocument();
    });

    it('labels a rejected item as changes requested and a pending item as pending', () => {
        render(
            <WaitingItemsSummary
                documentRequests={[
                    request({
                        id: 1,
                        title: 'Bank statement',
                        status: 'pending',
                    }),
                    request({
                        id: 2,
                        title: 'ID document',
                        status: 'rejected',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('Pending')).toBeInTheDocument();
        expect(screen.getByText('Changes requested')).toBeInTheDocument();
    });
});
