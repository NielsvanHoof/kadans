import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { WorkspaceDashboardMetrics } from '@/features/dashboard/types';
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

const { default: DashboardMetricsGrid } =
    await import('@/features/dashboard/dashboard-metrics-grid');

function metrics(
    overrides: Partial<WorkspaceDashboardMetrics> = {},
): WorkspaceDashboardMetrics {
    return {
        clients: 10,
        open_dossiers: 0,
        needs_review: 0,
        awaiting_client: 0,
        overdue: 0,
        in_review: 0,
        submitted_document_requests: 0,
        outstanding_document_requests: 0,
        open_dossiers_this_week: 0,
        open_dossiers_last_week: 0,
        needs_review_this_week: 0,
        needs_review_last_week: 0,
        awaiting_client_this_week: 0,
        awaiting_client_last_week: 0,
        ...overrides,
    };
}

describe('DashboardMetricsGrid', () => {
    it('highlights the busiest stage as the workflow focus', () => {
        render(
            <DashboardMetricsGrid
                metrics={metrics({ open_dossiers: 1, needs_review: 5 })}
            />,
        );

        const reviewLink = screen.getByText('Review').closest('a');
        const prepareLink = screen.getByText('Build Form').closest('a');

        expect(reviewLink?.className).toContain('border-primary/40');
        expect(prepareLink?.className).not.toContain('border-primary/40');
    });

    it('defaults the focus to the first stage when every count is tied', () => {
        render(<DashboardMetricsGrid metrics={metrics()} />);

        const prepareLink = screen.getByText('Build Form').closest('a');

        expect(prepareLink?.className).toContain('border-primary/40');
    });

    it('shows a checkmark instead of the step number for an empty, non-focus stage', () => {
        render(
            <DashboardMetricsGrid
                metrics={metrics({ open_dossiers: 5, awaiting_client: 0 })}
            />,
        );

        const inviteLabel = screen.getByText('Send Invite');
        const badge = inviteLabel.parentElement?.querySelector(
            'span:first-child',
        ) as HTMLElement;

        expect(badge.textContent).toBe('');
        expect(badge.querySelector('svg')).not.toBeNull();
    });

    it('shows the ordinal step number for the focus stage even when its count is zero', () => {
        render(<DashboardMetricsGrid metrics={metrics()} />);

        const prepareLabel = screen.getByText('Build Form');
        const badge = prepareLabel.parentElement?.querySelector(
            'span:first-child',
        ) as HTMLElement;

        expect(badge.textContent).toBe('1');
        expect(badge.querySelector('svg')).toBeNull();
    });

    it('sizes the weekly activity bar relative to the busier of the two weeks', () => {
        const { container } = render(
            <DashboardMetricsGrid
                metrics={metrics({
                    needs_review: 1,
                    needs_review_this_week: 3,
                    needs_review_last_week: 1,
                })}
            />,
        );

        const thisWeekBar = container.querySelector(
            '.bg-chart-3',
        ) as HTMLElement;
        const lastWeekBar = thisWeekBar.nextElementSibling as HTMLElement;

        expect(thisWeekBar.style.width).toBe('100%');
        expect(lastWeekBar.style.width).toBe(`${(1 / 3) * 100}%`);
    });
});
