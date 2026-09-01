import { CircleSlash } from 'lucide-react';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { cancel as cancelPipeline } from '@/routes/workspaces/pipelines';

/**
 * Stop a queued or running run. Gated on the server's `can_cancel` prop, which
 * covers both the permission and the run still being open — the server decides,
 * this only hides the control.
 */
export default function CancelPipelineButton({
    pipelineId,
    canCancel,
}: {
    pipelineId: number;
    canCancel: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    if (!canCancel) {
        return null;
    }

    return (
        <ConfirmDestroyDialog
            title={t('Cancel this job?')}
            description={t(
                'The run stops where it is and its remaining steps are skipped. The document or export is released so it can be processed again later.',
            )}
            confirmLabel={t('Cancel pipeline')}
            form={cancelPipeline.form({
                tenant: currentWorkspace,
                pipeline: pipelineId,
            })}
            trigger={
                <Button variant="outline" type="button">
                    <CircleSlash aria-hidden="true" />
                    {t('Cancel run')}
                </Button>
            }
        />
    );
}
