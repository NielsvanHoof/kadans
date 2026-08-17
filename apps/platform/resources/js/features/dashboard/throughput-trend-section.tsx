import { lazy, Suspense } from 'react';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { DashboardTrendPoint } from '@/features/dashboard/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Loads the trend chart only in the browser.
 *
 * The trend data ships in the first response rather than as a deferred prop, so
 * unlike the other cards this one would otherwise be pulled into the server
 * render — and into the dashboard's initial chunk. recharts brings d3, redux
 * and immer with it, which is a lot of bytes to hand someone before first paint
 * for a chart below the fold.
 *
 * `lazy` keeps it out of both: renderToString sees a suspended boundary and
 * emits the skeleton, and the bundler splits the chart into its own chunk.
 */
const ThroughputTrendCard = lazy(
    () => import('@/features/dashboard/throughput-trend-card'),
);

function TrendSkeleton({ label }: { label: string }) {
    return (
        <Card aria-busy="true" aria-label={label}>
            <CardHeader className="space-y-2">
                <Skeleton className="h-5 w-44" />
                <Skeleton className="h-4 w-60" />
            </CardHeader>
            <CardContent>
                <Skeleton className="h-[180px] w-full" />
            </CardContent>
        </Card>
    );
}

export default function ThroughputTrendSection({
    trend,
}: {
    trend: DashboardTrendPoint[];
}) {
    const { t } = useTranslation();

    return (
        <Suspense fallback={<TrendSkeleton label={t('Loading trend')} />}>
            <ThroughputTrendCard trend={trend} />
        </Suspense>
    );
}
