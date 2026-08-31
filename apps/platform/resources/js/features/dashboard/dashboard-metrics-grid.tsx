import { Link } from '@inertiajs/react';
import { Check, ClipboardList, Users } from 'lucide-react';
import type { WorkspaceDashboardMetrics } from '@/features/dashboard/types';
import { dossierStageLabel } from '@/features/dossiers/stage/dossier-stage';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { index as clientIndex } from '@/routes/workspaces/clients';
import { index as dossierIndex } from '@/routes/workspaces/dossiers';

/**
 * Dashboard workflow spine — stage-first metrics with weekly activity trends.
 */
export default function DashboardMetricsGrid({
    metrics,
}: {
    metrics: WorkspaceDashboardMetrics;
}) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();

    const stages = [
        {
            key: 'prepare' as const,
            count: metrics.open_dossiers,
            description: t('Open dossiers in progress'),
            href: dossierIndex(currentWorkspace),
            detail: t(':count outstanding requests', {
                count: metrics.outstanding_document_requests,
            }),
            thisWeek: metrics.open_dossiers_this_week,
            lastWeek: metrics.open_dossiers_last_week,
            chartClass: 'bg-chart-1',
        },
        {
            key: 'invite' as const,
            count: metrics.awaiting_client,
            description: t('Waiting for client uploads'),
            href: dossierIndex(currentWorkspace, {
                query: { filter: { queue: 'awaiting_client' } },
            }),
            detail: t('Portal invitations and reminders'),
            thisWeek: metrics.awaiting_client_this_week,
            lastWeek: metrics.awaiting_client_last_week,
            chartClass: 'bg-chart-2',
        },
        {
            key: 'review' as const,
            count: metrics.needs_review,
            description: t('Submitted items ready for staff'),
            href: dossierIndex(currentWorkspace, {
                query: { filter: { queue: 'needs_review' } },
            }),
            detail: t(':count in review', { count: metrics.in_review }),
            thisWeek: metrics.needs_review_this_week,
            lastWeek: metrics.needs_review_last_week,
            chartClass: 'bg-chart-3',
        },
    ];

    // Highlight the busiest stage so the spine has a clear current focus.
    const focusKey = stages.reduce((busiest, stage) =>
        stage.count > busiest.count ? stage : busiest,
    ).key;

    return (
        <div className="space-y-4">
            {/* The lane supplies the heading; this row carries the client count. */}
            <div className="flex justify-end">
                <Link
                    href={clientIndex(currentWorkspace)}
                    className="hover:bg-muted/50 focus-visible:ring-ring inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    <Users
                        className="text-muted-foreground size-4"
                        aria-hidden
                    />
                    <span className="text-muted-foreground">
                        {t('Clients')}
                    </span>
                    <span className="font-data text-base font-semibold tabular-nums">
                        {metrics.clients}
                    </span>
                </Link>
            </div>

            <nav aria-label={t('Dossier workflow')}>
                <ol className="flex flex-col gap-3 lg:flex-row lg:items-stretch">
                    {stages.map((stage, index) => {
                        const isFocus = stage.key === focusKey;
                        const isLast = index === stages.length - 1;
                        const maxWeek = Math.max(
                            stage.thisWeek,
                            stage.lastWeek,
                            1,
                        );

                        return (
                            <li
                                key={stage.key}
                                className="flex min-w-0 flex-1 items-stretch gap-3"
                            >
                                <Link
                                    href={stage.href}
                                    className={cn(
                                        'group bg-card hover:bg-muted/40 focus-visible:ring-ring flex min-w-0 flex-1 flex-col rounded-xl border p-4 shadow-sm transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                        isFocus &&
                                            'border-primary/40 ring-primary/20 ring-1',
                                    )}
                                >
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={cn(
                                                'flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium',
                                                isFocus
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : stage.count === 0
                                                      ? 'border-success-border bg-success-muted text-success-foreground'
                                                      : 'border-border bg-background text-muted-foreground',
                                            )}
                                        >
                                            {stage.count === 0 && !isFocus ? (
                                                <Check
                                                    className="size-3.5"
                                                    aria-hidden
                                                />
                                            ) : (
                                                index + 1
                                            )}
                                        </span>
                                        <span className="text-sm font-medium">
                                            {dossierStageLabel(stage.key, t)}
                                        </span>
                                    </div>
                                    <p className="font-data mt-3 text-3xl font-semibold tracking-tight tabular-nums">
                                        {stage.count}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {stage.description}
                                    </p>

                                    {/* Weekly activity comparison — uses chart tokens. */}
                                    <div className="mt-3 space-y-1.5">
                                        <p className="font-data text-muted-foreground text-xs tabular-nums">
                                            {t(
                                                ':this this week · :last last week',
                                                {
                                                    this: stage.thisWeek,
                                                    last: stage.lastWeek,
                                                },
                                            )}
                                        </p>
                                        <div
                                            className="flex h-1.5 gap-1"
                                            aria-hidden="true"
                                        >
                                            <span
                                                className={cn(
                                                    'rounded-full',
                                                    stage.chartClass,
                                                )}
                                                style={{
                                                    width: `${(stage.thisWeek / maxWeek) * 100}%`,
                                                    minWidth:
                                                        stage.thisWeek > 0
                                                            ? '0.25rem'
                                                            : 0,
                                                }}
                                            />
                                            <span
                                                className="bg-muted-foreground/25 rounded-full"
                                                style={{
                                                    width: `${(stage.lastWeek / maxWeek) * 100}%`,
                                                    minWidth:
                                                        stage.lastWeek > 0
                                                            ? '0.25rem'
                                                            : 0,
                                                }}
                                            />
                                        </div>
                                    </div>

                                    <p className="text-muted-foreground mt-3 flex items-center gap-1.5 text-xs">
                                        <ClipboardList
                                            className="size-3.5 shrink-0"
                                            aria-hidden
                                        />
                                        {stage.detail}
                                    </p>
                                </Link>

                                {!isLast && (
                                    <div
                                        aria-hidden
                                        className="hidden items-center justify-center lg:flex"
                                    >
                                        <span className="bg-border h-px w-6" />
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>
            </nav>
        </div>
    );
}
