import { ArrowDown, CheckCircle2, Clock3 } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import type { PortalDossier } from '@/features/portal/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Top-of-portal progress cue — one job: show what’s left and jump to the next item.
 */
export default function PortalProgressOverview({
    dossier,
    firmName,
}: {
    dossier: PortalDossier;
    firmName: string;
}) {
    const { t } = useTranslation();
    const { formatDate } = useFormatters();
    const progress = dossier.progress;

    if (progress.total === 0) {
        return null;
    }

    const isComplete = progress.remaining === 0;

    return (
        <section
            className={cn(
                'rounded-2xl border px-4 py-4 md:px-5 md:py-5',
                isComplete
                    ? 'border-success-border bg-success-muted'
                    : 'border-border/70 bg-card shadow-xs',
            )}
            aria-label={t('Dossier progress')}
        >
            <div className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-center">
                <div className="min-w-0 space-y-3">
                    <div className="flex items-start gap-3">
                        <span
                            className={cn(
                                'flex size-9 shrink-0 items-center justify-center rounded-full',
                                isComplete
                                    ? 'bg-success-muted text-success-foreground'
                                    : 'bg-primary/10 text-primary',
                            )}
                        >
                            {isComplete ? (
                                <CheckCircle2
                                    className="size-5"
                                    aria-hidden="true"
                                />
                            ) : (
                                <Clock3 className="size-5" aria-hidden="true" />
                            )}
                        </span>
                        <div className="min-w-0 space-y-1">
                            <h2 className="text-sm font-semibold tracking-tight text-pretty">
                                {isComplete
                                    ? t('Everything has been submitted')
                                    : t(':count items remaining', {
                                          count: progress.remaining,
                                      })}
                            </h2>
                            <p className="text-sm text-pretty text-muted-foreground">
                                {isComplete
                                    ? t(
                                          ':firm will review your information. You can return here if changes are requested.',
                                          { firm: firmName },
                                      )
                                    : dossier.due_date
                                      ? t('Please finish by :date.', {
                                            // Calendar day — formatDate, not
                                            // formatDateTime, so the deadline
                                            // does not shift by a day.
                                            date: formatDate(dossier.due_date),
                                        })
                                      : t(
                                            'Complete the next item below, then continue through the list.',
                                        )}
                            </p>
                        </div>
                    </div>

                    {/*
                      Taller, fully rounded track than the compact default —
                      this is the client's main progress cue, not a chip.
                    */}
                    <Progress
                        value={progress.completed}
                        minValue={0}
                        maxValue={progress.total}
                        aria-label={t('Dossier progress')}
                        className="[&_[data-slot=progress-indicator]]:rounded-full [&_[data-slot=progress-track]]:h-2 [&_[data-slot=progress-track]]:rounded-full"
                    />
                    <p className="font-data text-xs text-muted-foreground tabular-nums">
                        {t(
                            ':completed of :total complete · :approved approved',
                            {
                                completed: progress.completed,
                                total: progress.total,
                                approved: progress.approved,
                            },
                        )}
                    </p>
                </div>

                {progress.next_incomplete ? (
                    <a
                        className={cn(buttonVariants(), 'w-full sm:w-auto')}
                        href={`#request-${progress.next_incomplete.id}`}
                    >
                        {t('Continue')}
                        <ArrowDown aria-hidden="true" />
                    </a>
                ) : null}
            </div>
        </section>
    );
}
