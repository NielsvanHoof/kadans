import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import PipelineTechnicalError from '@/features/pipelines/show/pipeline-technical-error';
import type { PipelineDetail } from '@/features/pipelines/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Provider identifiers and raw error detail for a pipeline run.
 *
 * Recovery timing and the localised failure sentence live in the recovery
 * banner — this card is the technical evidence an engineer needs.
 */
export default function PipelineShowDiagnostics({
    pipeline,
}: {
    pipeline: PipelineDetail;
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>{t('Diagnostics')}</CardTitle>
                    <CardDescription>
                        {t(
                            'Provider identifiers and the raw error for this run.',
                        )}
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
                    </div>
                    <PipelineTechnicalError
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
                        <pre className="bg-muted overflow-x-auto rounded-md p-3 font-mono text-xs leading-relaxed">
                            {JSON.stringify(pipeline.metadata, null, 2)}
                        </pre>
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
