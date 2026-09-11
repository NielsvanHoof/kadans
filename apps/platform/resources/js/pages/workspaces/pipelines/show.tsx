import { Head, setLayoutProps } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useReloadOnPipelineUpdated } from '@/features/pipelines/hooks/use-reload-on-pipeline-updated';
import PipelineRecoveryGuidance from '@/features/pipelines/show/pipeline-recovery-guidance';
import PipelineShowDiagnostics from '@/features/pipelines/show/pipeline-show-diagnostics';
import PipelineShowHeader from '@/features/pipelines/show/pipeline-show-header';
import PipelineShowSummary from '@/features/pipelines/show/pipeline-show-summary';
import PipelineStepTimeline from '@/features/pipelines/show/pipeline-step-timeline';
import { pipelineNeedsLiveReload } from '@/features/pipelines/status/pipeline-status';
import type { PipelineDetail } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as pipelineIndex } from '@/routes/workspaces/pipelines';

export default function PipelineShow({
    pipeline,
    can_delete: canDelete = false,
    can_download: canDownload = false,
    can_retry: canRetry = false,
    can_cancel: canCancel = false,
}: {
    pipeline: PipelineDetail;
    can_delete?: boolean;
    can_download?: boolean;
    can_retry?: boolean;
    can_cancel?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    // Keep the show page live while the run is moving, and while a failed run
    // still has an automatic retry booked — otherwise the recovery banner
    // would stay on "Retry scheduled" after the retry already fired.
    useReloadOnPipelineUpdated(
        [
            'pipeline',
            'can_delete',
            'can_download',
            'can_retry',
            'can_cancel',
            'flash',
        ],
        {
            enabled: pipelineNeedsLiveReload(
                pipeline.status,
                pipeline.next_retry_at,
            ),
            // Firm-wide: a run reached from the pipeline list is not
            // necessarily one whose dossier this page knows.
            scope: { on: 'workspace' },
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
                    canCancel={canCancel}
                />

                <PipelineRecoveryGuidance pipeline={pipeline} />

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
