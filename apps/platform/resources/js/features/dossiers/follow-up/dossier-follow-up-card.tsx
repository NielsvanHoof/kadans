import { CalendarClock, MailCheck, MousePointerClick } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { FieldLegend, FieldSet } from '@/components/ui/field';
import DossierReminderComposeDialog from '@/features/dossiers/follow-up/dossier-reminder-compose-dialog';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import type { Dossier } from '@/features/dossiers/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Staff glance strip: due/sent/opened facts, then a single reminder action.
 * Facts and CTA stay on separate rows so long locale labels never crush the button.
 */
export default function DossierFollowUpCard({ dossier }: { dossier: Dossier }) {
    const { canSendReminder } = useDossierCapabilities();
    const { t } = useTranslation();
    const { formatDateTime, formatDate } = useFormatters();
    const canRemind = canSendReminder && dossier.has_outstanding_client_items;

    return (
        <Card className="overflow-hidden border-primary/15">
            <CardContent className="grid gap-0 p-0">
                <FieldSet className="m-0 grid min-w-0 gap-0 border-0 p-0 md:grid-cols-3">
                    <FieldLegend className="sr-only">
                        {t('Follow-up status')}
                    </FieldLegend>
                    <FollowUpDatum
                        icon={CalendarClock}
                        label={t('Due and owner')}
                        value={
                            dossier.due_date
                                ? formatDate(dossier.due_date)
                                : t('No due date')
                        }
                        detail={
                            dossier.responsible_staff?.name ?? t('Unassigned')
                        }
                    />
                    <FollowUpDatum
                        icon={MailCheck}
                        label={t('Last sent')}
                        value={
                            dossier.last_client_message_sent_at
                                ? formatDateTime(dossier.last_client_message_sent_at)
                                : t('Not sent yet')
                        }
                        detail={
                            dossier.next_reminder_at
                                ? t('Next reminder :datetime', {
                                      datetime: formatDateTime(dossier.next_reminder_at),
                                  })
                                : t('No reminder scheduled')
                        }
                    />
                    <FollowUpDatum
                        icon={MousePointerClick}
                        label={t('Last opened')}
                        value={
                            dossier.last_client_opened_at
                                ? formatDateTime(dossier.last_client_opened_at)
                                : t('Not opened yet')
                        }
                        detail={
                            dossier.reminder_interval_days
                                ? t('Automatic every :days days', {
                                      days: dossier.reminder_interval_days,
                                  })
                                : t('Automatic reminders off')
                        }
                    />
                </FieldSet>

                {/* Facts above, act below — quiet prompt left, CTA right when available. */}
                <div className="flex flex-col gap-3 border-t bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-muted-foreground">
                        {canRemind
                            ? t('Send a reminder to nudge the client.')
                            : dossier.has_outstanding_client_items
                              ? t('You cannot send reminders for this dossier.')
                              : t('No client follow-up is needed.')}
                    </p>

                    {canRemind ? (
                        <div className="shrink-0">
                            <DossierReminderComposeDialog
                                dossierId={dossier.id}
                            />
                        </div>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

function FollowUpDatum({
    icon: Icon,
    label,
    value,
    detail,
}: {
    icon: typeof CalendarClock;
    label: string;
    value: string;
    detail: string;
}) {
    return (
        <div className="flex min-w-0 gap-3 border-t p-4 first:border-t-0 md:border-t-0 md:border-l md:first:border-l-0">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                <Icon className="size-4" aria-hidden="true" />
            </span>
            <span className="min-w-0">
                <span className="block text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </span>
                <span
                    className="block truncate font-data text-sm font-medium tabular-nums"
                    title={value}
                >
                    {value}
                </span>
                <span
                    className="block truncate text-xs text-muted-foreground"
                    title={detail}
                >
                    {detail}
                </span>
            </span>
        </div>
    );
}
