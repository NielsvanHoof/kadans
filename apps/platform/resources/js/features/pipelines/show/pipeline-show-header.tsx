import { Link } from '@inertiajs/react';
import { ArrowLeft, Download, Trash2 } from 'lucide-react';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import Heading from '@/components/primitives/heading';
import { Button, buttonVariants } from '@/components/ui/button';
import CancelPipelineButton from '@/features/pipelines/show/cancel-pipeline-button';
import RetryPipelineButton from '@/features/pipelines/show/retry-pipeline-button';
import { pipelineTypeLabel } from '@/features/pipelines/status/pipeline-status';
import PipelineStatusBadge from '@/features/pipelines/status/pipeline-status-badge';
import type { PipelineDetail } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    destroy as destroyPipeline,
    download as downloadPipeline,
    index as pipelineIndex,
} from '@/routes/workspaces/pipelines';

export default function PipelineShowHeader({
    pipeline,
    canDelete = false,
    canDownload = false,
    canRetry = false,
    canCancel = false,
}: {
    pipeline: PipelineDetail;
    canDelete?: boolean;
    canDownload?: boolean;
    canRetry?: boolean;
    canCancel?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="space-y-3">
                <Link
                    href={pipelineIndex(currentWorkspace)}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-2 text-sm"
                >
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    {t('All pipelines')}
                </Link>
                <Heading
                    level={1}
                    className="mb-0"
                    title={`${pipelineTypeLabel(pipeline.type, t)} #${pipeline.id}`}
                    description={t('Step-by-step details for this job.')}
                    badge={<PipelineStatusBadge status={pipeline.status} />}
                />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {/* Plain anchor, not LinkButton: RouterProvider hands
                    every React Aria href to Inertia, which would
                    swallow this file download. */}
                {canDownload ? (
                    <a
                        className={buttonVariants({
                            variant: 'outline',
                        })}
                        href={downloadPipeline.url({
                            tenant: currentWorkspace,
                            pipeline: pipeline.id,
                        })}
                    >
                        <Download aria-hidden="true" />
                        {t('Download raw data')}
                    </a>
                ) : null}

                <RetryPipelineButton
                    pipelineId={pipeline.id}
                    canRetry={canRetry}
                />

                <CancelPipelineButton
                    pipelineId={pipeline.id}
                    canCancel={canCancel}
                />

                {canDelete ? (
                    <ConfirmDestroyDialog
                        title={t('Delete this job?')}
                        description={t(
                            'This removes the execution record and its step history. It does not delete the underlying dossier or document.',
                        )}
                        confirmLabel={t('Delete pipeline')}
                        form={destroyPipeline.form({
                            tenant: currentWorkspace,
                            pipeline: pipeline.id,
                        })}
                        trigger={
                            <Button variant="destructive" type="button">
                                <Trash2 aria-hidden="true" />
                                {t('Delete')}
                            </Button>
                        }
                    />
                ) : null}
            </div>
        </div>
    );
}
