import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type {
    DashboardAgendaItem,
    DashboardMyWork,
} from '@/features/dashboard/types';
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

const { default: MyWorkCard } =
    await import('@/features/dashboard/my-work-card');

function agendaItem(
    overrides: Partial<DashboardAgendaItem> = {},
): DashboardAgendaItem {
    return {
        id: 1,
        title: 'Annual audit',
        client_name: 'Acme Corp',
        status: 'awaiting_client',
        due_date: '2026-09-01',
        days_remaining: 3,
        ...overrides,
    };
}

function myWork(overrides: Partial<DashboardMyWork> = {}): DashboardMyWork {
    return {
        assigned_open: 0,
        due_this_week: 0,
        overdue: 0,
        awaiting_my_review: 0,
        agenda: [],
        ...overrides,
    };
}

describe('MyWorkCard', () => {
    it('invites the user to be assigned work when they have none', () => {
        render(<MyWorkCard myWork={myWork()} />);

        expect(
            screen.getByText('Nothing is assigned to you right now.'),
        ).toBeInTheDocument();
    });

    it('always shows the three counters, including at zero', () => {
        render(<MyWorkCard myWork={myWork()} />);

        expect(screen.getByText('Assigned to you')).toBeInTheDocument();
        expect(screen.getByText('Awaiting your review')).toBeInTheDocument();
        expect(screen.getByText('Overdue')).toBeInTheDocument();
    });

    it('lists the agenda when the user has dated work', () => {
        render(
            <MyWorkCard
                myWork={myWork({
                    assigned_open: 2,
                    agenda: [
                        agendaItem(),
                        agendaItem({ id: 2, title: 'VAT return' }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('Annual audit')).toBeInTheDocument();
        expect(screen.getByText('VAT return')).toBeInTheDocument();
        expect(
            screen.queryByText('Nothing is assigned to you right now.'),
        ).not.toBeInTheDocument();
    });

    it('distinguishes having no deadlines from having no work at all', () => {
        render(
            <MyWorkCard myWork={myWork({ assigned_open: 4, agenda: [] })} />,
        );

        // Assigned work exists, it simply has no dates in the next week.
        expect(
            screen.getByText('Nothing of yours is due in the next 7 days.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Nothing is assigned to you right now.'),
        ).not.toBeInTheDocument();
    });

    it('links each agenda row to its dossier', () => {
        render(
            <MyWorkCard
                myWork={myWork({ assigned_open: 1, agenda: [agendaItem()] })}
            />,
        );

        expect(
            screen.getByRole('link', { name: /Annual audit/ }),
        ).toHaveAttribute('href', expect.stringContaining('/dossiers/1'));
    });
});
