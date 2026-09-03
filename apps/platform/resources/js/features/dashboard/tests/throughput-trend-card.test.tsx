import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { DashboardTrendPoint } from '@/features/dashboard/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: ThroughputTrendCard } =
    await import('@/features/dashboard/throughput-trend-card');

function week(
    weekStart: string,
    documentsReceived: number,
): DashboardTrendPoint {
    return { week_start: weekStart, documents_received: documentsReceived };
}

const eightWeeks: DashboardTrendPoint[] = [
    week('2026-06-22', 3),
    week('2026-06-29', 0),
    week('2026-07-06', 5),
    week('2026-07-13', 2),
    week('2026-07-20', 0),
    week('2026-07-27', 4),
    week('2026-08-03', 6),
    week('2026-08-10', 1),
];

describe('ThroughputTrendCard', () => {
    it('summarises the total received across the window', () => {
        render(<ThroughputTrendCard trend={eightWeeks} />);

        expect(
            screen.getByText('21 received over the last 8 weeks'),
        ).toBeInTheDocument();
    });

    it('says so plainly when nothing has come in', () => {
        render(
            <ThroughputTrendCard
                trend={eightWeeks.map((point) => ({
                    ...point,
                    documents_received: 0,
                }))}
            />,
        );

        expect(
            screen.getByText(
                'No documents have come in over the last 8 weeks.',
            ),
        ).toBeInTheDocument();
    });

    it('renders a labelled axis tick for every bucket, including empty weeks', () => {
        const { container } = render(
            <ThroughputTrendCard trend={eightWeeks} />,
        );

        // A gap on a date axis reads as "no data", which is a different claim
        // from "nothing happened" — so zero weeks must still be plotted.
        const ticks = container.querySelectorAll(
            '.recharts-cartesian-axis-tick',
        );

        expect(ticks.length).toBe(eightWeeks.length);
    });

    it('mounts without a real ResizeObserver implementation', () => {
        // ResponsiveContainer measures its box; the suite stubs the observer,
        // and ChartContainer supplies initial dimensions so it still paints.
        expect(() =>
            render(<ThroughputTrendCard trend={eightWeeks} />),
        ).not.toThrow();
    });
});
