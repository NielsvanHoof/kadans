import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type {
    DashboardFollowUpItem,
    DashboardFollowUpSignals,
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

const { default: FollowUpSignalsCard } = await import(
    '@/features/dashboard/follow-up-signals-card'
);

function item(
    overrides: Partial<DashboardFollowUpItem> = {},
): DashboardFollowUpItem {
    return {
        id: 1,
        title: 'Annual audit',
        client_name: 'Acme Corp',
        since: '2026-08-01T00:00:00+00:00',
        days: 9,
        ...overrides,
    };
}

function signals(
    overrides: Partial<DashboardFollowUpSignals> = {},
): DashboardFollowUpSignals {
    return {
        never_opened: [],
        gone_quiet: [],
        expiring_links: [],
        total_count: 0,
        ...overrides,
    };
}

describe('FollowUpSignalsCard', () => {
    it('reports the all-clear when no client has stalled', () => {
        render(<FollowUpSignalsCard signals={signals()} />);

        expect(screen.getByText('No follow-up needed')).toBeInTheDocument();
    });

    it('hides a group that has no rows rather than showing an empty heading', () => {
        render(
            <FollowUpSignalsCard
                signals={signals({ never_opened: [item()], total_count: 1 })}
            />,
        );

        expect(
            screen.getByText('Invited but never opened'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Portal links expiring soon'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('No client activity')).not.toBeInTheDocument();
    });

    it('renders every populated group at once', () => {
        render(
            <FollowUpSignalsCard
                signals={signals({
                    never_opened: [item()],
                    gone_quiet: [item({ id: 2, title: 'VAT return' })],
                    expiring_links: [item({ id: 3, title: 'Payroll', days: 2 })],
                    total_count: 3,
                })}
            />,
        );

        expect(screen.getByText('Annual audit')).toBeInTheDocument();
        expect(screen.getByText('VAT return')).toBeInTheDocument();
        expect(screen.getByText('Payroll')).toBeInTheDocument();
    });

    it('keys rows by group so one dossier can appear in two groups', () => {
        // The same dossier can legitimately be both quiet and expiring.
        render(
            <FollowUpSignalsCard
                signals={signals({
                    gone_quiet: [item()],
                    expiring_links: [item()],
                    total_count: 2,
                })}
            />,
        );

        expect(screen.getAllByText('Annual audit')).toHaveLength(2);
    });

    it('says a link expires today rather than counting zero days', () => {
        render(
            <FollowUpSignalsCard
                signals={signals({
                    expiring_links: [item({ days: 0 })],
                    total_count: 1,
                })}
            />,
        );

        expect(screen.getByText('Expires today')).toBeInTheDocument();
    });

    it('falls back to a wordless caption when the timestamp is missing', () => {
        render(
            <FollowUpSignalsCard
                signals={signals({
                    never_opened: [item({ since: null, days: null })],
                    total_count: 1,
                })}
            />,
        );

        expect(screen.getByText('Invitation sent')).toBeInTheDocument();
    });
});
