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

const { default: ReviewQueueCard } =
    await import('@/features/dashboard/review-queue-card');

type Metrics = Pick<
    WorkspaceDashboardMetrics,
    'needs_review' | 'awaiting_client' | 'overdue'
>;

function metrics(overrides: Partial<Metrics> = {}): Metrics {
    return {
        needs_review: 0,
        awaiting_client: 0,
        overdue: 0,
        ...overrides,
    };
}

describe('ReviewQueueCard', () => {
    it('shows the all-caught-up empty state when every queue is at zero', () => {
        render(<ReviewQueueCard metrics={metrics()} />);

        expect(screen.getByText('All caught up')).toBeInTheDocument();
        expect(screen.queryByText('Needs review')).not.toBeInTheDocument();
        expect(screen.queryByText('Awaiting client')).not.toBeInTheDocument();
        expect(screen.queryByText('Overdue')).not.toBeInTheDocument();
    });

    it('renders every queue link once any queue has work outstanding', () => {
        render(<ReviewQueueCard metrics={metrics({ needs_review: 3 })} />);

        expect(screen.queryByText('All caught up')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Needs review/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Awaiting client/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Overdue/ }),
        ).toBeInTheDocument();
    });

    it('shows the count for each queue even when other queues are empty', () => {
        render(
            <ReviewQueueCard
                metrics={metrics({ awaiting_client: 4, overdue: 2 })}
            />,
        );

        const awaitingLink = screen.getByRole('link', {
            name: /Awaiting client/,
        });
        const overdueLink = screen.getByRole('link', { name: /Overdue/ });

        expect(awaitingLink).toHaveTextContent('4');
        expect(overdueLink).toHaveTextContent('2');
    });
});
