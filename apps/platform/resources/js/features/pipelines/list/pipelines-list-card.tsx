import { Link } from '@inertiajs/react';
import { Activity, ArrowRight } from 'lucide-react';
import type { DataTableColumn } from '@/components/data/data-table';
import IndexDataCard from '@/components/data/index-data-card';
import EmptyState from '@/components/primitives/empty-state';
import PipelineStatusBadge from '@/features/pipelines/status/pipeline-status-badge';
import {
    pipelineStepLabel,
    pipelineTypeLabel,
} from '@/features/pipelines/status/pipeline-status';
import type { PipelineIndexRow } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format-date-time';
import { show as showPipeline } from '@/routes/workspaces/pipelines';
import type { Paginated } from '@/types/pagination';

export default function PipelinesListCard({
    pipelines,
}: {
    pipelines: Paginated<PipelineIndexRow>;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t, locale } = useTranslation();

    const columns: DataTableColumn<PipelineIndexRow>[] = [
        {
            key: 'type',
            header: t('Process'),
            cell: (pipeline) => (
                <div className="space-y-1">
                    <Link
                        href={showPipeline({
                            tenant: currentWorkspace,
                            pipeline: pipeline.id,
                        })}
                        className="font-medium hover:underline"
                    >
                        {pipelineTypeLabel(pipeline.type, t)}
                    </Link>
                    <p className="font-mono text-xs text-muted-foreground">
                        #{pipeline.id}
                    </p>
                </div>
            ),
        },
        {
            key: 'subject',
            header: t('Subject'),
            className: 'hidden whitespace-normal sm:table-cell',
            cell: (pipeline) => (
                <div className="text-sm">
                    <span className="text-foreground">
                        {pipeline.subject_type}
                    </span>
                    <span className="font-mono text-muted-foreground">
                        {' '}
                        #{pipeline.subject_id}
                    </span>
                </div>
            ),
        },
        {
            key: 'status',
            header: t('Status'),
            cell: (pipeline) => (
                <PipelineStatusBadge status={pipeline.status} />
            ),
        },
        {
            key: 'current_stage',
            header: t('Current step'),
            className: 'hidden md:table-cell',
            cell: (pipeline) => (
                <span className="text-sm text-muted-foreground">
                    {pipeline.current_stage
                        ? pipelineStepLabel(pipeline.current_stage, t)
                        : t('—')}
                </span>
            ),
        },
        {
            key: 'last_activity_at',
            header: t('Last activity'),
            className: 'hidden lg:table-cell',
            cell: (pipeline) => (
                <span className="text-sm text-muted-foreground">
                    {pipeline.last_activity_at
                        ? formatDateTime(pipeline.last_activity_at, locale)
                        : formatDateTime(pipeline.created_at, locale)}
                </span>
            ),
        },
        {
            key: 'open',
            header: '',
            className: 'w-10',
            cell: (pipeline) => (
                <Link
                    href={showPipeline({
                        tenant: currentWorkspace,
                        pipeline: pipeline.id,
                    })}
                    aria-label={t('View pipeline :id', { id: pipeline.id })}
                    className="inline-flex rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <ArrowRight aria-hidden="true" className="size-4" />
                </Link>
            ),
        },
    ];

    return (
        <IndexDataCard
            title={t('Pipeline runs')}
            description={
                pipelines.total === 1
                    ? t('1 pipeline run')
                    : t(':count pipeline runs', { count: pipelines.total })
            }
            columns={columns}
            rows={pipelines.data}
            rowKey={(pipeline) => pipeline.id}
            emptyState={
                <EmptyState
                    icon={Activity}
                    title={t('No pipeline runs found.')}
                    description={t(
                        'Long-running OCR and export processes will appear here as they run.',
                    )}
                />
            }
        />
    );
}
