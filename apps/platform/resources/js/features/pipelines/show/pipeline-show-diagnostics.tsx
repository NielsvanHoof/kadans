import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import PipelineErrorPanel from '@/features/pipelines/show/pipeline-error-panel';
import type { PipelineDetail } from '@/features/pipelines/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Provider / retry diagnostics and optional metadata JSON for a pipeline run.
 */
export default function PipelineShowDiagnostics({
    pipeline,
}: {
    pipeline: PipelineDetail;
}) {
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>{t('Diagnostics')}</CardTitle>
                    <CardDescription>
                        {t('Provider and retry context for this run.')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4 text-sm">
                    <div className="grid gap-3">
                        <div>
                            <p className="text-muted-foreground">
                                {t('Provider')}
                            </p>
                            <p className="font-mono">
                                {pipeline.provider ?? t('—')}
                            </p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">
                                {t('Provider job ID')}
                            </p>
                            <p className="font-mono text-xs break-all">
                                {pipeline.provider_job_id ?? t('—')}
                            </p>
                        </div>
                        {pipeline.next_retry_at ? (
                            <div>
                                <p className="text-muted-foreground">
                                    {t('Next retry')}
                                </p>
                                <p>{formatDateTime(pipeline.next_retry_at)}</p>
                            </div>
                        ) : null}
                    </div>
                    <PipelineErrorPanel
                        errorCode={pipeline.error_code}
                        errorMessage={pipeline.error_message}
                    />
                </CardContent>
            </Card>

            {pipeline.metadata ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Metadata')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs leading-relaxed">
                            {JSON.stringify(pipeline.metadata, null, 2)}
                        </pre>
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
