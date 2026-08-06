import { Link } from '@inertiajs/react';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { pipelineStepLabel } from '@/features/pipelines/status/pipeline-status';
import type { PipelineDetail } from '@/features/pipelines/types';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format-date-time';

/**
 * Subject card plus the four summary metric cards on the pipeline show page.
 */
export default function PipelineShowSummary({
    pipeline,
}: {
    pipeline: PipelineDetail;
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Card className="border-l-4 border-l-primary">
                <CardHeader className="pb-3">
                    <CardDescription>{t('Subject')}</CardDescription>
                    <CardTitle className="text-lg">
                        {pipeline.subject.href ? (
                            <Link
                                href={pipeline.subject.href}
                                className="hover:underline"
                            >
                                {pipeline.subject.label ??
                                    `${pipeline.subject.type} #${pipeline.subject.id}`}
                            </Link>
                        ) : (
                            (pipeline.subject.label ??
                            `${pipeline.subject.type} #${pipeline.subject.id}`)
                        )}
                    </CardTitle>
                    {pipeline.subject.context ? (
                        <CardDescription>
                            {pipeline.subject.context}
                        </CardDescription>
                    ) : null}
                </CardHeader>
            </Card>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader className="pb-2">
                        <CardDescription>{t('Current step')}</CardDescription>
                        <CardTitle className="text-base">
                            {pipeline.current_stage
                                ? pipelineStepLabel(pipeline.current_stage, t)
                                : t('No active step')}
                        </CardTitle>
                    </CardHeader>
                </Card>
                <Card>
                    <CardHeader className="pb-2">
                        <CardDescription>{t('Attempts')}</CardDescription>
                        <CardTitle className="font-mono text-base">
                            {pipeline.attempt_number}
                        </CardTitle>
                    </CardHeader>
                </Card>
                <Card>
                    <CardHeader className="pb-2">
                        <CardDescription>{t('Started')}</CardDescription>
                        <CardTitle className="text-sm">
                            {pipeline.started_at
                                ? formatDateTime(pipeline.started_at, locale)
                                : t('Not started')}
                        </CardTitle>
                    </CardHeader>
                </Card>
                <Card>
                    <CardHeader className="pb-2">
                        <CardDescription>{t('Last activity')}</CardDescription>
                        <CardTitle className="text-sm">
                            {pipeline.last_activity_at
                                ? formatDateTime(
                                      pipeline.last_activity_at,
                                      locale,
                                  )
                                : t('No activity yet')}
                        </CardTitle>
                    </CardHeader>
                </Card>
            </div>
        </>
    );
}
