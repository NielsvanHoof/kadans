import { Deferred, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import FollowUpSignalsCard from '@/features/dashboard/follow-up-signals-card';
import MyWorkCard from '@/features/dashboard/my-work-card';
import ProcessingHealthCard from '@/features/dashboard/processing-health-card';
import type {
    DashboardFollowUpSignals,
    DashboardMyWork,
    DashboardProcessingHealth,
} from '@/features/dashboard/types';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Placeholder row widths, doubling as stable keys.
 *
 * Naming the rows rather than counting them keeps React's reconciliation keyed
 * on identity, and lets the skeleton echo the ragged shape of real content
 * instead of three identical bars.
 */
const SKELETON_ROWS = ['full', 'wide', 'narrow'] as const;

const SKELETON_ROW_WIDTHS: Record<(typeof SKELETON_ROWS)[number], string> = {
    full: 'w-full',
    wide: 'w-11/12',
    narrow: 'w-3/4',
};

/**
 * Pulsing placeholder while a deferred dashboard prop resolves.
 */
function CardSkeleton({ label, rows = 3 }: { label: string; rows?: number }) {
    return (
        <Card aria-busy="true" aria-label={label}>
            <CardHeader className="space-y-2">
                <Skeleton className="h-5 w-40" />
                <Skeleton className="h-4 w-56" />
            </CardHeader>
            <CardContent className="space-y-2">
                {SKELETON_ROWS.slice(0, rows).map((row) => (
                    <Skeleton
                        key={row}
                        className={cn('h-12', SKELETON_ROW_WIDTHS[row])}
                    />
                ))}
            </CardContent>
        </Card>
    );
}

/**
 * The signed-in user's own queue.
 *
 * Deferred because it is per-user: the dashboard metrics cache is keyed by
 * tenant and date with no user segment, so this can never join it.
 */
export function DeferredMyWork() {
    const { t } = useTranslation();

    return (
        <Deferred
            data="my_work"
            fallback={<CardSkeleton label={t('Loading your work')} />}
        >
            <MyWorkSection />
        </Deferred>
    );
}

function MyWorkSection() {
    // Deferred children do not receive the prop — read it off the page.
    const myWork = usePage().props.my_work as DashboardMyWork;

    return <MyWorkCard myWork={myWork} />;
}

/**
 * Client stalls. Deferred because ClientAccessGrant and the portal-open
 * timestamp do not invalidate the dashboard cache, so these must stay live.
 */
export function DeferredFollowUpSignals() {
    const { t } = useTranslation();

    return (
        <Deferred
            data="follow_up_signals"
            fallback={<CardSkeleton label={t('Loading client signals')} />}
        >
            <FollowUpSignalsSection />
        </Deferred>
    );
}

function FollowUpSignalsSection() {
    const signals = usePage().props
        .follow_up_signals as DashboardFollowUpSignals;

    return <FollowUpSignalsCard signals={signals} />;
}

/**
 * Background-run health. The caller checks `can_view_processing_health` first,
 * so this never waits on a prop the server refused to send.
 */
export function DeferredProcessingHealth() {
    const { t } = useTranslation();

    return (
        <Deferred
            data="processing_health"
            fallback={
                <CardSkeleton label={t('Loading processing health')} rows={2} />
            }
        >
            <ProcessingHealthSection />
        </Deferred>
    );
}

function ProcessingHealthSection() {
    const health = usePage().props
        .processing_health as DashboardProcessingHealth;

    return <ProcessingHealthCard health={health} />;
}
