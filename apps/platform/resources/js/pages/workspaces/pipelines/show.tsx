import { Head, setLayoutProps } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useReloadOnPipelineUpdated } from '@/features/pipelines/hooks/use-reload-on-pipeline-updated';
import PipelineShowDiagnostics from '@/features/pipelines/show/pipeline-show-diagnostics';
import PipelineShowHeader from '@/features/pipelines/show/pipeline-show-header';
import PipelineShowSummary from '@/features/pipelines/show/pipeline-show-summary';
import PipelineStepTimeline from '@/features/pipelines/show/pipeline-step-timeline';
import { pipelineIsInFlight } from '@/features/pipelines/status/pipeline-status';
import type { PipelineDetail } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as pipelineIndex } from '@/routes/workspaces/pipelines';

export default function PipelineShow({
    pipeline,
    can_delete: canDelete = false,
    can_download: canDownload = false,
    can_retry: canRetry = false,
}: {
    pipeline: PipelineDetail;
    can_delete?: boolean;
    can_download?: boolean;
    can_retry?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    // Keep the show page live while submit / provider / results are still moving.
    const isInFlight = pipelineIsInFlight(pipeline.status);
    useReloadOnPipelineUpdated(
        ['pipeline', 'can_delete', 'can_download', 'can_retry', 'flash'],
        {
            enabled: isInFlight,
            match: (event) => event.pipeline_id === pipeline.id,
        },
    );

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Pipelines'),
                href: pipelineIndex(currentWorkspace),
            },
            {
                title: `#${pipeline.id}`,
                href: pipelineIndex(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={`${t('Pipeline')} #${pipeline.id}`} />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <PipelineShowHeader
                    pipeline={pipeline}
                    canDelete={canDelete}
                    canDownload={canDownload}
                    canRetry={canRetry}
                />

                <PipelineShowSummary pipeline={pipeline} />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Execution steps')}</CardTitle>
                            <CardDescription>
                                {t(
                                    'Each step records its own status, attempts, timing, and failure context.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <PipelineStepTimeline steps={pipeline.steps} />
                        </CardContent>
                    </Card>

                    <PipelineShowDiagnostics pipeline={pipeline} />
                </div>
            </div>
        </>
    );
}
