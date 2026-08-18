import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { DashboardRecentDossier } from '@/features/dashboard/types';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@inertiajs/react', () => ({
    usePage: () =>
        mockInertiaPage({ current_firm: { slug: 'acme', name: 'Acme' } }),
    Link: ({
        href,
        children,
        className,
    }: {
        href: string | { url: string };
        children: React.ReactNode;
        className?: string;
    }) => (
        <a
            href={typeof href === 'string' ? href : href.url}
            className={className}
        >
            {children}
        </a>
    ),
}));

const { default: RecentDossiersCard } =
    await import('@/features/dashboard/recent-dossiers-card');

function dossier(
    overrides: Partial<DashboardRecentDossier> = {},
): DashboardRecentDossier {
    return {
        id: 1,
        client_name: 'Acme Corp',
        title: 'Annual audit',
        reference: null,
        status: 'draft',
        due_date: null,
        responsible_name: null,
        updated_at: '2026-01-01T00:00:00+00:00',
        ...overrides,
    };
}

describe('RecentDossiersCard', () => {
    it('shows an empty state with a create action when there are no dossiers', () => {
        render(<RecentDossiersCard dossiers={[]} />);

        expect(
            screen.getByText('Create a dossier to start collecting documents.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'New dossier' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('Annual audit')).not.toBeInTheDocument();
    });

    it('appends the reference only when one is present', () => {
        render(
            <RecentDossiersCard
                dossiers={[dossier({ reference: 'REF-42' })]}
            />,
        );

        expect(screen.getByText(/Acme Corp · REF-42/)).toBeInTheDocument();
    });

    it('does not render a reference separator when the dossier has none', () => {
        render(<RecentDossiersCard dossiers={[dossier()]} />);

        expect(screen.getByText('Acme Corp')).toBeInTheDocument();
    });

    it('omits the due-date line entirely when neither due date nor responsible name is set', () => {
        render(<RecentDossiersCard dossiers={[dossier()]} />);

        expect(screen.queryByText('No due date')).not.toBeInTheDocument();
        expect(screen.queryByText(/Due :date/)).not.toBeInTheDocument();
    });

    it('falls back to "No due date" when only a responsible name is set', () => {
        render(
            <RecentDossiersCard
                dossiers={[dossier({ responsible_name: 'Jamie Lee' })]}
            />,
        );

        expect(screen.getByText(/No due date/)).toBeInTheDocument();
        expect(screen.getByText(/Jamie Lee/)).toBeInTheDocument();
    });

    it('shows the due date line when a due date is set, without the responsible name separator', () => {
        render(
            <RecentDossiersCard
                dossiers={[dossier({ due_date: '2026-01-01' })]}
            />,
        );

        expect(screen.getByText(/Due :date/)).toBeInTheDocument();
    });
});
