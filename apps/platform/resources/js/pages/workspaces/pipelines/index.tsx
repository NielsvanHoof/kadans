import { Head, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/heading';
import IndexPagination from '@/components/index-query/index-pagination';
import IndexQueryResults from '@/components/index-query/index-query-results';
import IndexQueryToolbar from '@/components/index-query/index-query-toolbar';
import PipelinesListCard from '@/features/pipelines/list/pipelines-list-card';
import { pipelineIsInFlight } from '@/features/pipelines/status/pipeline-status';
import type { PipelineIndexRow } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useReloadOnPipelineUpdated } from '@/hooks/use-reload-on-pipeline-updated';
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
        { enabled: hasInFlightPipeline },
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
                        'Monitor OCR, exports, and other long-running processes across this workspace.',
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
