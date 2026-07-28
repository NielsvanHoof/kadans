import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import ConfirmDestroyDialog from '@/components/confirm-destroy-dialog';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import PipelineStepTimeline from '@/features/pipelines/show/pipeline-step-timeline';
import {
    pipelineStatusBadgeVariant,
    pipelineStatusLabel,
    pipelineStepLabel,
    pipelineTypeLabel,
} from '@/features/pipelines/status/pipeline-status';
import type { PipelineDetail } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format-date-time';
import {
    destroy as destroyPipeline,
    index as pipelineIndex,
} from '@/routes/workspaces/pipelines';

export default function PipelineShow({
    pipeline,
    can_delete: canDelete = false,
}: {
    pipeline: PipelineDetail;
    can_delete?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t, locale } = useTranslation();

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
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-3">
                        <Link
                            href={pipelineIndex(currentWorkspace)}
                            className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft aria-hidden="true" className="size-4" />
                            {t('All pipelines')}
                        </Link>
                        <Heading
                            level={1}
                            className="mb-0"
                            title={`${pipelineTypeLabel(pipeline.type, t)} #${pipeline.id}`}
                            description={t(
                                'A detailed execution trace for this long-running process.',
                            )}
                            badge={
                                <Badge
                                    variant={pipelineStatusBadgeVariant(
                                        pipeline.status,
                                    )}
                                >
                                    {pipelineStatusLabel(pipeline.status, t)}
                                </Badge>
                            }
                        />
                    </div>

                    {canDelete ? (
                        <ConfirmDestroyDialog
                            title={t('Delete pipeline run?')}
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
                            <CardDescription>
                                {t('Current step')}
                            </CardDescription>
                            <CardTitle className="text-base">
                                {pipeline.current_stage
                                    ? pipelineStepLabel(
                                          pipeline.current_stage,
                                          t,
                                      )
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
                                    ? formatDateTime(
                                          pipeline.started_at,
                                          locale,
                                      )
                                    : t('Not started')}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                {t('Last activity')}
                            </CardDescription>
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

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Diagnostics')}</CardTitle>
                                <CardDescription>
                                    {t(
                                        'Provider and retry context for this run.',
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
                                    {pipeline.next_retry_at ? (
                                        <div>
                                            <p className="text-muted-foreground">
                                                {t('Next retry')}
                                            </p>
                                            <p>
                                                {formatDateTime(
                                                    pipeline.next_retry_at,
                                                    locale,
                                                )}
                                            </p>
                                        </div>
                                    ) : null}
                                </div>
                                {pipeline.error_code ||
                                pipeline.error_message ? (
                                    <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2">
                                        {pipeline.error_code ? (
                                            <p className="font-mono text-xs text-destructive">
                                                {pipeline.error_code}
                                            </p>
                                        ) : null}
                                        {pipeline.error_message ? (
                                            <p className="text-destructive">
                                                {pipeline.error_message}
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>

                        {pipeline.metadata ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t('Metadata')}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs leading-relaxed">
                                        {JSON.stringify(
                                            pipeline.metadata,
                                            null,
                                            2,
                                        )}
                                    </pre>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </div>
        </>
    );
}
