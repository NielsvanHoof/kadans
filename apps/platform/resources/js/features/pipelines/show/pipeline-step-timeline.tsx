import { Check, Circle, CircleAlert, Clock3, SkipForward } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    pipelineStepLabel,
    pipelineStepStatusLabel,
    pipelineStepStatusTone,
} from '@/features/pipelines/status/pipeline-status';
import type { PipelineStepDetail } from '@/features/pipelines/types';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format-date-time';

function StepIcon({ status }: { status: PipelineStepDetail['status'] }) {
    if (status === 'completed') {
        return <Check aria-hidden="true" className="size-4" />;
    }

    if (status === 'failed' || status === 'stuck') {
        return <CircleAlert aria-hidden="true" className="size-4" />;
    }

    if (status === 'skipped') {
        return <SkipForward aria-hidden="true" className="size-4" />;
    }

    if (status === 'running') {
        return <Clock3 aria-hidden="true" className="size-4" />;
    }

    return <Circle aria-hidden="true" className="size-4" />;
}

export default function PipelineStepTimeline({
    steps,
}: {
    steps: PipelineStepDetail[];
}) {
    const { t, locale } = useTranslation();

    return (
        <div className="space-y-0">
            {steps.map((step, index) => (
                <div
                    key={step.key}
                    className="relative flex gap-4 pb-7 last:pb-0"
                >
                    {index < steps.length - 1 ? (
                        <span
                            aria-hidden="true"
                            className="absolute top-8 left-3.5 h-[calc(100%-1.5rem)] w-px bg-border"
                        />
                    ) : null}
                    <div
                        className={`relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full border ${pipelineStepStatusTone(step.status)}`}
                    >
                        <StepIcon status={step.status} />
                    </div>
                    <div className="min-w-0 flex-1 space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p className="font-medium">
                                    {pipelineStepLabel(step.key, t)}
                                </p>
                                <p className="font-mono text-xs text-muted-foreground">
                                    {step.key}
                                </p>
                            </div>
                            <Badge
                                variant={
                                    step.status === 'failed' ||
                                    step.status === 'stuck'
                                        ? 'destructive'
                                        : step.status === 'completed'
                                          ? 'default'
                                          : 'outline'
                                }
                            >
                                {pipelineStepStatusLabel(step.status, t)}
                            </Badge>
                        </div>
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span>
                                {t(':count attempts', {
                                    count: step.attempts,
                                })}
                            </span>
                            {step.started_at ? (
                                <span>
                                    {t('Started :datetime', {
                                        datetime: formatDateTime(
                                            step.started_at,
                                            locale,
                                        ),
                                    })}
                                </span>
                            ) : null}
                            {step.completed_at ? (
                                <span>
                                    {t('Finished :datetime', {
                                        datetime: formatDateTime(
                                            step.completed_at,
                                            locale,
                                        ),
                                    })}
                                </span>
                            ) : null}
                        </div>
                        {step.error_message || step.error_code ? (
                            <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm">
                                {step.error_code ? (
                                    <p className="font-mono text-xs text-destructive">
                                        {step.error_code}
                                    </p>
                                ) : null}
                                {step.error_message ? (
                                    <p className="text-destructive">
                                        {step.error_message}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                </div>
            ))}
        </div>
    );
}
