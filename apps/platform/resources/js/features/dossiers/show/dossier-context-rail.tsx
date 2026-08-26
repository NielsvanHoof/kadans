import DaysRemainingLabel from '@/components/primitives/days-remaining-label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Progress,
    ProgressLabel,
    ProgressValue,
} from '@/components/ui/progress';
import { Separator } from '@/components/ui/separator';
import DossierChaseLine from '@/features/dossiers/follow-up/dossier-chase-line';
import type { Dossier } from '@/features/dossiers/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Status facts that used to sit above the waiting list.
 *
 * Desktop: a sticky paper card beside the workbench. Mobile: the same body
 * inside a Sheet, so the outstanding items stay on screen.
 */
export default function DossierContextRail({
    dossier,
    className,
}: {
    dossier: Dossier;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <aside
            aria-label={t('Dossier status')}
            className={cn('min-w-0', className)}
        >
            <Card size="sm" className="gap-0 py-0">
                <CardHeader className="border-b py-3">
                    <CardTitle className="text-sm font-semibold">
                        {t('Dossier status')}
                    </CardTitle>
                </CardHeader>
                <CardContent className="py-4">
                    <DossierContextRailBody dossier={dossier} />
                </CardContent>
            </Card>
        </aside>
    );
}

/** Shared by the desktop rail card and the mobile status sheet. */
export function DossierContextRailBody({ dossier }: { dossier: Dossier }) {
    const { t } = useTranslation();
    const { formatDate } = useFormatters();
    const summary = dossier.review_summary;
    const total = summary.total;
    const accepted = summary.accepted;

    return (
        <div className="space-y-4">
            <section className="min-w-0 space-y-1">
                <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('Client')}
                </h3>
                <p className="truncate text-sm font-medium">
                    {dossier.client.name}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    {dossier.client.email}
                </p>
            </section>

            <Separator />

            <section className="min-w-0 space-y-1">
                <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('Due')}
                </h3>
                <p className="font-data text-sm font-medium">
                    {dossier.due_date
                        ? formatDate(dossier.due_date)
                        : t('No due date')}
                </p>
                {dossier.days_remaining !== null ? (
                    <DaysRemainingLabel
                        daysRemaining={dossier.days_remaining}
                        className="text-xs"
                    />
                ) : null}
            </section>

            <Separator />

            <section className="min-w-0 space-y-3">
                <h3 className="scroll-mt-20 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('Follow-up')}
                </h3>
                <DossierChaseLine
                    dossier={dossier}
                    orientation="vertical"
                    showMeta={false}
                    showDueRemaining={false}
                />
            </section>

            <Separator />

            <section className="min-w-0 space-y-1">
                <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('Owner')}
                </h3>
                <p className="text-sm">
                    {dossier.responsible_staff?.name ?? t('Unassigned')}
                </p>
                <p className="text-xs text-muted-foreground">
                    {dossier.reminder_interval_days
                        ? t('reminder every :days days', {
                              days: dossier.reminder_interval_days,
                          })
                        : t('Automatic reminders off')}
                </p>
            </section>

            <Separator />

            <section className="min-w-0 space-y-3">
                <Progress
                    value={accepted}
                    maxValue={Math.max(total, 1)}
                    className="w-full flex-col gap-1.5"
                    aria-label={t('Review progress')}
                >
                    <div className="flex w-full items-baseline justify-between gap-2">
                        <ProgressLabel>{t('Review progress')}</ProgressLabel>
                        <ProgressValue>
                            {() =>
                                t(':accepted of :total approved', {
                                    accepted,
                                    total,
                                })
                            }
                        </ProgressValue>
                    </div>
                </Progress>

                <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                    <ReviewCount label={t('Waiting')} value={summary.pending} />
                    <ReviewCount
                        label={t('Ready to review')}
                        value={summary.submitted}
                    />
                    <ReviewCount
                        label={t('Changes requested')}
                        value={summary.rejected}
                    />
                    <ReviewCount
                        label={t('Approved')}
                        value={summary.accepted}
                    />
                </dl>
            </section>
        </div>
    );
}

function ReviewCount({ label, value }: { label: string; value: number }) {
    return (
        <div className="min-w-0">
            <dt className="truncate text-muted-foreground">{label}</dt>
            <dd className="font-data text-sm font-medium tabular-nums">
                {value}
            </dd>
        </div>
    );
}
