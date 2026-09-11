import { Clock3, TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    pipelineErrorCodeLabel,
    pipelineRecoveryKind,
} from '@/features/pipelines/status/pipeline-status';
import type { PipelineDetail } from '@/features/pipelines/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Outcome-first recovery guidance for a failed, stuck, or cancelled run.
 *
 * Separates "wait for the automatic retry" from "press Retry" so staff do not
 * have to infer that from next_retry_at buried in Diagnostics. Technical
 * error_code / error_message stay in the diagnostics sidebar.
 */
export default function PipelineRecoveryGuidance({
    pipeline,
}: {
    pipeline: PipelineDetail;
}) {
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();

    const kind = pipelineRecoveryKind(pipeline.status, pipeline.next_retry_at);

    if (kind === null) {
        return null;
    }

    const sentence =
        pipeline.error_code === null
            ? null
            : pipelineErrorCodeLabel(pipeline.error_code, t);

    if (kind === 'scheduled' && pipeline.next_retry_at !== null) {
        return (
            <Alert className="border-warning-border bg-warning-muted text-warning-foreground">
                <Clock3 aria-hidden />
                <AlertTitle>{t('Retry scheduled')}</AlertTitle>
                <AlertDescription>
                    {sentence !== null ? <p>{sentence}</p> : null}
                    <p>
                        {t(
                            "We'll try this job again at :datetime. Use Retry if you want to start now.",
                            {
                                datetime: formatDateTime(
                                    pipeline.next_retry_at,
                                ),
                            },
                        )}
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Alert variant="destructive">
            <TriangleAlert aria-hidden />
            <AlertTitle>{staffActionTitle(pipeline.status, t)}</AlertTitle>
            <AlertDescription>
                {sentence !== null ? <p>{sentence}</p> : null}
                <p>
                    {t(
                        'It will not try again on its own. Use Retry to start it again.',
                    )}
                </p>
            </AlertDescription>
        </Alert>
    );
}

function staffActionTitle(
    status: PipelineDetail['status'],
    t: (key: string) => string,
): string {
    if (status === 'stuck') {
        return t('This job is stuck');
    }

    if (status === 'cancelled') {
        return t('This job was cancelled');
    }

    return t('This job needs a retry');
}
