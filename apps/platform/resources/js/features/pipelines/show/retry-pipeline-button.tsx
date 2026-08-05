import { RotateCcw } from 'lucide-react';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { retry as retryPipeline } from '@/routes/workspaces/pipelines';

/**
 * Re-queue a failed or stuck run. Gated on the server's `can_retry` prop, which
 * covers both the permission and the run being in a retryable state — the
 * server decides, this only hides the control.
 */
export default function RetryPipelineButton({
    pipelineId,
    canRetry,
}: {
    pipelineId: number;
    canRetry: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    if (!canRetry) {
        return null;
    }

    return (
        <ConfirmDestroyDialog
            title={t('Retry pipeline run?')}
            description={t(
                'The run restarts from its first step. Results from the failed attempt are discarded.',
            )}
            confirmLabel={t('Retry pipeline')}
            confirmVariant="default"
            form={retryPipeline.form({
                tenant: currentWorkspace,
                pipeline: pipelineId,
            })}
            trigger={
                <Button variant="outline" type="button">
                    <RotateCcw aria-hidden="true" />
                    {t('Retry')}
                </Button>
            }
        />
    );
}
