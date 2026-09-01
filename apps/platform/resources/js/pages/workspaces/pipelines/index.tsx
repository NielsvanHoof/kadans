import { Head, setLayoutProps } from '@inertiajs/react';
import IndexPagination from '@/components/data/index-query/index-pagination';
import IndexQueryResults from '@/components/data/index-query/index-query-results';
import IndexQueryToolbar from '@/components/data/index-query/index-query-toolbar';
import Heading from '@/components/primitives/heading';
import { useReloadOnPipelineUpdated } from '@/features/pipelines/hooks/use-reload-on-pipeline-updated';
import PipelinesListCard from '@/features/pipelines/list/pipelines-list-card';
import { pipelineIsInFlight } from '@/features/pipelines/status/pipeline-status';
import type { PipelineIndexRow } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as pipelineIndex } from '@/routes/workspaces/pipelines';
import type { IndexQueryConfig, Paginated } from '@/types/pagination';

export default function PipelineIndex({
    pipelines,
    indexQuery,
}: {
    pipelines: Paginated<PipelineIndexRow>;
    indexQuery: IndexQueryConfig;
    filters?: Record<string, string>;
    sort?: string | null;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    const hasInFlightPipeline = pipelines.data.some((pipeline) =>
        pipelineIsInFlight(pipeline.status),
    );
    useReloadOnPipelineUpdated(
        ['pipelines', 'filters', 'sort', 'indexQuery', 'flash'],
        // The one surface that genuinely wants every run in the firm.
        { enabled: hasInFlightPipeline, scope: { on: 'workspace' } },
    );

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Pipelines'),
                href: pipelineIndex(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Pipelines')} />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <Heading
                    level={1}
                    className="mb-0"
                    title={t('Pipelines')}
                    description={t(
                        'Track document scans, exports, and other background jobs.',
                    )}
                />

                <IndexQueryToolbar
                    config={indexQuery}
                    only={[
                        'pipelines',
                        'filters',
                        'sort',
                        'indexQuery',
                        'flash',
                    ]}
                />
                <IndexQueryResults className="space-y-6">
                    <PipelinesListCard pipelines={pipelines} />
                    <IndexPagination paginator={pipelines} />
                </IndexQueryResults>
            </div>
        </>
    );
}
