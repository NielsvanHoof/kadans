import { Head, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/primitives/heading';
import { LinkButton } from '@/components/ui/button';
import {
    DeferredFollowUpSignals,
    DeferredMyWork,
    DeferredProcessingHealth,
} from '@/features/dashboard/dashboard-deferred-sections';
import DashboardLane from '@/features/dashboard/dashboard-lane';
import DashboardMetricsGrid from '@/features/dashboard/dashboard-metrics-grid';
import RecentDossiersCard from '@/features/dashboard/recent-dossiers-card';
import ReviewQueueCard from '@/features/dashboard/review-queue-card';
import ThroughputTrendSection from '@/features/dashboard/throughput-trend-section';
import type {
    DashboardRecentDossier,
    WorkspaceDashboardMetrics,
} from '@/features/dashboard/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard as workspaceDashboard } from '@/routes/workspaces';
import { create as createClient } from '@/routes/workspaces/clients';
import { create as createDossier } from '@/routes/workspaces/dossiers';

/**
 * Workspace dashboard, in three lanes: what is yours, what needs attention,
 * and how the firm is doing.
 *
 * Only the firm-wide lane is in the first response. The personal and
 * attention lanes arrive as deferred props so the page paints immediately —
 * and, as a side effect, so their charts and relative timestamps never run
 * during server rendering.
 */
export default function WorkspaceDashboard({
    metrics,
    recent_dossiers: recentDossiers,
    can_view_processing_health: canViewProcessingHealth = false,
}: {
    metrics: WorkspaceDashboardMetrics;
    recent_dossiers: DashboardRecentDossier[];
    can_view_processing_health?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Dashboard'),
                href: workspaceDashboard(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Dashboard')} />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-muted-foreground text-sm font-medium">
                            {currentWorkspace.name}
                        </p>
                        <Heading
                            level={1}
                            className="mb-0"
                            title={t('Dossier overview')}
                            description={t(
                                'Keep track of client requests and review work.',
                            )}
                        />
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <LinkButton
                            variant="outline"
                            href={createClient(currentWorkspace).url}
                        >
                            {t('New client')}
                        </LinkButton>
                        <LinkButton href={createDossier(currentWorkspace).url}>
                            {t('New dossier')}
                        </LinkButton>
                    </div>
                </div>

                <DashboardLane
                    title={t('Your work')}
                    description={t('Assigned to you, soonest deadlines first.')}
                >
                    <DeferredMyWork />
                </DashboardLane>

                <DashboardLane
                    title={t('Needs attention')}
                    description={t(
                        'Work that is blocked, waiting, or has stalled.',
                    )}
                >
                    <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                        <DeferredFollowUpSignals />
                        <ReviewQueueCard metrics={metrics} />
                    </div>

                    {canViewProcessingHealth && <DeferredProcessingHealth />}
                </DashboardLane>

                <DashboardLane
                    title={t('Across the firm')}
                    description={t(
                        'Follow work from prepare through invite to review.',
                    )}
                >
                    <DashboardMetricsGrid metrics={metrics} />

                    <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                        <RecentDossiersCard dossiers={recentDossiers} />
                        <ThroughputTrendSection
                            trend={metrics.weekly_throughput}
                        />
                    </div>
                </DashboardLane>
            </div>
        </>
    );
}
