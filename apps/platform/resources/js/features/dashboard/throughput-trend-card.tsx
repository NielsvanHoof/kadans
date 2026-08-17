import { Bar, BarChart, CartesianGrid, XAxis } from 'recharts';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    type ChartConfig,
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { DashboardTrendPoint } from '@/features/dashboard/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Documents received per week, over the last eight weeks.
 *
 * Deliberately one series. Only `--chart-1` and `--chart-4` separate safely for
 * colour-vision deficiency — and only on lightness — while chart-1 against
 * chart-5 differs almost entirely on the red-green axis, which deuteranopia
 * removes. With one series the bar needs no legend: the card title names it.
 */
export default function ThroughputTrendCard({
    trend,
}: {
    trend: DashboardTrendPoint[];
}) {
    const { t } = useTranslation();
    const { formatDate } = useFormatters();

    const config = {
        documents_received: {
            label: t('Documents received'),
            // A var() reference, not a resolved colour: dark mode flips a class
            // on <html>, so this re-resolves with no JS.
            color: 'var(--chart-1)',
        },
    } satisfies ChartConfig;

    const data = trend.map((point) => ({
        ...point,
        label: formatDate(point.week_start, {
            day: 'numeric',
            month: 'short',
        }),
    }));

    const total = trend.reduce(
        (running, point) => running + point.documents_received,
        0,
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Documents received')}</CardTitle>
                <CardDescription>
                    {total === 0
                        ? t('No documents have come in over the last 8 weeks.')
                        : t(':count received over the last 8 weeks', {
                              count: total,
                          })}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ChartContainer config={config} className="h-[180px] w-full">
                    <BarChart data={data} accessibilityLayer>
                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="label"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                        />
                        <ChartTooltip content={<ChartTooltipContent />} />
                        <Bar
                            dataKey="documents_received"
                            fill="var(--color-documents_received)"
                            radius={[4, 4, 0, 0]}
                            maxBarSize={28}
                        />
                    </BarChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}
