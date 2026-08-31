import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { DossierStatus } from '@/features/dossiers/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: DossierStatusBadge } =
    await import('@/features/dossiers/status/dossier-status-badge');

describe('DossierStatusBadge', () => {
    it.each([
        ['draft', 'Draft'],
        ['awaiting_client', 'Awaiting client'],
        ['in_review', 'In review'],
        ['completed', 'Completed'],
    ] as [DossierStatus, string][])(
        'renders the label for %s',
        (status, label) => {
            render(<DossierStatusBadge status={status} />);

            expect(screen.getByText(label)).toBeInTheDocument();
        },
    );

    it('uses the warning styling only for awaiting_client, the sole "waiting on someone else" state', () => {
        render(<DossierStatusBadge status="awaiting_client" />);

        expect(screen.getByText('Awaiting client').closest('span')).toHaveClass(
            'bg-warning-muted',
        );
    });

    it('does not use warning styling for in_review, which is unfinished but on staff', () => {
        render(<DossierStatusBadge status="in_review" />);

        expect(screen.getByText('In review').closest('span')).not.toHaveClass(
            'bg-warning-muted',
        );
    });
});
