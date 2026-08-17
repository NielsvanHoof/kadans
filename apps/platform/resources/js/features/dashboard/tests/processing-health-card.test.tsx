import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type {
    DashboardPipelineFailure,
    DashboardProcessingHealth,
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

const { default: ProcessingHealthCard } = await import(
    '@/features/dashboard/processing-health-card'
);

function failure(
    overrides: Partial<DashboardPipelineFailure> = {},
): DashboardPipelineFailure {
    return {
        id: 7,
        type: 'ocr_document',
        status: 'failed',
        error_message: 'Provider returned 500',
        last_activity_at: '2026-08-17T10:00:00+00:00',
        is_retryable: true,
        ...overrides,
    };
}

function health(
    overrides: Partial<DashboardProcessingHealth> = {},
): DashboardProcessingHealth {
    return {
        failed: 0,
        stuck: 0,
        recent: [],
        ...overrides,
    };
}

describe('ProcessingHealthCard', () => {
    it('reports healthy when nothing has failed or stalled', () => {
        render(<ProcessingHealthCard health={health()} />);

        expect(
            screen.getByText('All processing runs are healthy'),
        ).toBeInTheDocument();
    });

    it('hides the view-all link while everything is healthy', () => {
        render(<ProcessingHealthCard health={health()} />);

        expect(
            screen.queryByRole('link', { name: /View all/ }),
        ).not.toBeInTheDocument();
    });

    it('surfaces failed and stuck counts separately', () => {
        render(
            <ProcessingHealthCard
                health={health({ failed: 3, stuck: 1, recent: [failure()] })}
            />,
        );

        // Queried by accessible name: "Failed" is also a status badge on the
        // run below, so a bare text match would find two nodes. The pattern
        // tolerates missing whitespace — jsdom applies no CSS, so the label and
        // count concatenate here where a browser would separate them.
        expect(
            screen.getByRole('link', { name: /^Failed\s*3$/ }),
        ).toHaveAttribute(
            'href',
            expect.stringContaining('status%5D=failed'),
        );
        expect(
            screen.getByRole('link', { name: /^Stuck\s*1$/ }),
        ).toHaveAttribute('href', expect.stringContaining('status%5D=stuck'));
    });

    it('shows a stuck-only workspace as unhealthy', () => {
        // failed is zero, so a naive truthiness check here would report healthy.
        render(
            <ProcessingHealthCard
                health={health({
                    stuck: 2,
                    recent: [failure({ status: 'stuck' })],
                })}
            />,
        );

        expect(
            screen.queryByText('All processing runs are healthy'),
        ).not.toBeInTheDocument();
    });

    it('links each run to its pipeline page and shows the error excerpt', () => {
        render(
            <ProcessingHealthCard
                health={health({ failed: 1, recent: [failure()] })}
            />,
        );

        expect(screen.getByText('Provider returned 500')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /OCR document/ }),
        ).toHaveAttribute('href', expect.stringContaining('/pipelines/7'));
    });

    it('renders a run that carries no error message', () => {
        render(
            <ProcessingHealthCard
                health={health({
                    stuck: 1,
                    recent: [failure({ status: 'stuck', error_message: null })],
                })}
            />,
        );

        expect(screen.getByText('OCR document')).toBeInTheDocument();
    });
});
