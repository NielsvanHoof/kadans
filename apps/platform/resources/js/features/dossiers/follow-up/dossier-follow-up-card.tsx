import { Form } from '@inertiajs/react';
import {
    CalendarClock,
    MailCheck,
    MousePointerClick,
    Send,
} from 'lucide-react';
import { useId } from 'react';
import DossierReminderController from '@/actions/App/Dossiers/Http/Controllers/DossierReminderController';
import InputError, { fieldErrorAriaProps } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import type { Dossier } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate, formatDateTime } from '@/lib/format-date-time';

/**
 * Staff glance strip: due/sent/opened facts, then a single reminder action.
 * Facts and CTA stay on separate rows so long locale labels never crush the button.
 */
export default function DossierFollowUpCard({ dossier }: { dossier: Dossier }) {
    const { canSendReminder } = useDossierCapabilities();
    const currentWorkspace = useCurrentWorkspace();
    const { t, locale } = useTranslation();
    const reminderErrorId = useId();
    const canRemind = canSendReminder && dossier.has_outstanding_client_items;

    return (
        <Card className="overflow-hidden border-primary/15">
            <CardContent className="grid gap-0 p-0">
                <div
                    className="grid gap-0 md:grid-cols-3"
                    role="group"
                    aria-label={t('Follow-up status')}
                >
                    <FollowUpDatum
                        icon={CalendarClock}
                        label={t('Due and owner')}
                        value={
                            dossier.due_date
                                ? // Date-only: avoid formatDateTime (adds a midnight clock).
                                  formatDate(
                                      `${dossier.due_date}T00:00:00Z`,
                                      locale,
                                  )
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
                                ? formatDateTime(
                                      dossier.last_client_message_sent_at,
                                      locale,
                                  )
                                : t('Not sent yet')
                        }
                        detail={
                            dossier.next_reminder_at
                                ? t('Next reminder :datetime', {
                                      datetime: formatDateTime(
                                          dossier.next_reminder_at,
                                          locale,
                                      ),
                                  })
                                : t('No reminder scheduled')
                        }
                    />
                    <FollowUpDatum
                        icon={MousePointerClick}
                        label={t('Last opened')}
                        value={
                            dossier.last_client_opened_at
                                ? formatDateTime(
                                      dossier.last_client_opened_at,
                                      locale,
                                  )
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
                </div>

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
                        <Form
                            {...DossierReminderController.store.form({
                                tenant: currentWorkspace,
                                dossier: dossier.id,
                            })}
                            className="shrink-0"
                        >
                            {({ errors, processing }) => (
                                <div className="flex flex-col gap-1 sm:items-end">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        {...fieldErrorAriaProps(
                                            reminderErrorId,
                                            errors.dossier,
                                        )}
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <Send aria-hidden="true" />
                                        )}
                                        {processing
                                            ? t('Sending…')
                                            : t('Send reminder')}
                                    </Button>
                                    <InputError
                                        id={reminderErrorId}
                                        message={errors.dossier}
                                    />
                                </div>
                            )}
                        </Form>
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
