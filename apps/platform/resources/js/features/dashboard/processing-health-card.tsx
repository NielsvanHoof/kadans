import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import EmptyState from '@/components/primitives/empty-state';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { DashboardProcessingHealth } from '@/features/dashboard/types';
import { pipelineTypeLabel } from '@/features/pipelines/status/pipeline-status';
import PipelineStatusBadge from '@/features/pipelines/status/pipeline-status-badge';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    index as pipelineIndex,
    show as showPipeline,
} from '@/routes/workspaces/pipelines';

/**
 * OCR, transcription and export runs that stopped needing a machine and started
 * needing a person.
 *
 * Rendered only when the server sent the data — a member without pipelines.view
 * never receives the prop at all, so this is not a client-side hide.
 */
export default function ProcessingHealthCard({
    health,
}: {
    health: DashboardProcessingHealth;
}) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();

    const total = health.failed + health.stuck;

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between space-y-0">
                <div>
                    <CardTitle>{t('Processing health')}</CardTitle>
                    <CardDescription>
                        {t(
                            'Failed or stuck document scans, transcriptions, and exports.',
                        )}
                    </CardDescription>
                </div>
                {total > 0 && (
                    <Link
                        href={pipelineIndex(currentWorkspace, {
                            query: { filter: { status: 'failed' } },
                        })}
                        className="text-muted-foreground focus-visible:ring-ring inline-flex shrink-0 items-center gap-1 text-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                    >
                        {t('View all')}
                        <ArrowUpRight className="size-3.5" aria-hidden />
                    </Link>
                )}
            </CardHeader>
            <CardContent className="space-y-3">
                {total === 0 ? (
                    <EmptyState
                        variant="compact"
                        title={t('All processing runs are healthy')}
                        description={t(
                            'Nothing has failed or stalled in the background.',
                        )}
                    />
                ) : (
                    <>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Link
                                href={pipelineIndex(currentWorkspace, {
                                    query: { filter: { status: 'failed' } },
                                })}
                                className="hover:bg-muted/50 focus-visible:ring-ring rounded-lg border px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <span className="text-muted-foreground block text-xs">
                                    {t('Failed')}
                                </span>
                                <span className="font-data mt-1 block text-xl font-semibold tabular-nums">
                                    {health.failed}
                                </span>
                            </Link>
                            <Link
                                href={pipelineIndex(currentWorkspace, {
                                    query: { filter: { status: 'stuck' } },
                                })}
                                className="hover:bg-muted/50 focus-visible:ring-ring rounded-lg border px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <span className="text-muted-foreground block text-xs">
                                    {t('Stuck')}
                                </span>
                                <span className="font-data mt-1 block text-xl font-semibold tabular-nums">
                                    {health.stuck}
                                </span>
                            </Link>
                        </div>

                        <ul className="space-y-1.5">
                            {health.recent.map((run) => (
                                <li key={run.id}>
                                    <Link
                                        href={showPipeline({
                                            tenant: currentWorkspace,
                                            pipeline: run.id,
                                        })}
                                        className="hover:bg-muted/50 focus-visible:ring-ring block rounded-lg border px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <span className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="text-sm font-medium">
                                                {pipelineTypeLabel(run.type, t)}
                                            </span>
                                            <PipelineStatusBadge
                                                status={run.status}
                                            />
                                        </span>
                                        {run.error_message && (
                                            <span className="text-muted-foreground mt-1 block truncate text-xs">
                                                {run.error_message}
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
