import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { DossierWorkflowProgress } from '@/features/dossiers/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

const useDossierCapabilities = vi.fn();

vi.mock('@/features/dossiers/permissions/dossier-permissions-context', () => ({
    useDossierCapabilities,
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children: (bag: {
            errors: object;
            processing: boolean;
        }) => React.ReactNode;
    }) => children({ errors: {}, processing: false }),
}));

const { default: DossierWorkflowCard } =
    await import('@/features/dossiers/show/dossier-workflow-card');

function dossier(
    overrides: Partial<DossierWorkflowProgress> = {},
): DossierWorkflowProgress {
    return {
        id: 1,
        status: 'in_review',
        ready_to_complete: false,
        review_summary: {
            pending: 1,
            submitted: 2,
            rejected: 0,
            accepted: 3,
        },
        ...overrides,
    } as DossierWorkflowProgress;
}

describe('DossierWorkflowCard', () => {
    it('shows review counts from the summary', () => {
        useDossierCapabilities.mockReturnValue({ canReview: true });

        render(<DossierWorkflowCard dossier={dossier()} />);

        expect(screen.getByText('1')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('Waiting')).toBeInTheDocument();
        expect(screen.getByText('Ready to review')).toBeInTheDocument();
        expect(screen.getByText('Approved')).toBeInTheDocument();
    });

    it('shows the completed banner when the dossier is finished', () => {
        useDossierCapabilities.mockReturnValue({ canReview: true });

        render(
            <DossierWorkflowCard dossier={dossier({ status: 'completed' })} />,
        );

        expect(screen.getByText('Dossier completed')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Complete dossier' }),
        ).not.toBeInTheDocument();
    });

    it('disables complete until every item is approved', () => {
        useDossierCapabilities.mockReturnValue({ canReview: true });

        const { rerender } = render(
            <DossierWorkflowCard
                dossier={dossier({ ready_to_complete: false })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Complete dossier' }),
        ).toBeDisabled();
        expect(
            screen.getByText('Every item must be approved first.'),
        ).toBeInTheDocument();

        rerender(
            <DossierWorkflowCard
                dossier={dossier({ ready_to_complete: true })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Complete dossier' }),
        ).not.toBeDisabled();
    });

    it('hides the complete action from users without review permission', () => {
        useDossierCapabilities.mockReturnValue({ canReview: false });

        render(<DossierWorkflowCard dossier={dossier()} />);

        expect(
            screen.queryByRole('button', { name: 'Complete dossier' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'A reviewer will complete the dossier after all items have been approved.',
            ),
        ).toBeInTheDocument();
    });
});
