import DaysRemainingLabel from '@/components/primitives/days-remaining-label';
import type { Dossier } from '@/features/dossiers/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * The chase, as one line.
 *
 * These four dates are not four facts — they are one story: you sent it, the
 * client opened it, a reminder is queued, the deadline lands. Presented as
 * separate stat cells they read as unrelated; on a line, "sent but not opened,
 * reminder queued" is legible from the marks alone.
 *
 * Deliberately evenly spaced rather than to scale. Gaps between these dates are
 * irregular and any of them can be missing, so a proportional axis would be a
 * lie — the line carries order, not duration.
 */
type ChaseMark = {
    key: string;
    label: string;
    /** Absent means it has not happened (or is not scheduled) — a hollow mark. */
    value: string | null;
    placeholder: string;
};

export default function DossierChaseLine({ dossier }: { dossier: Dossier }) {
    const { t } = useTranslation();
    const { formatDate } = useFormatters();

    const marks: ChaseMark[] = [
        {
            key: 'sent',
            label: t('Sent'),
            value: dossier.last_client_message_sent_at
                ? formatDate(dossier.last_client_message_sent_at)
                : null,
            placeholder: t('Not sent yet'),
        },
        {
            key: 'opened',
            label: t('Opened'),
            value: dossier.last_client_opened_at
                ? formatDate(dossier.last_client_opened_at)
                : null,
            placeholder: t('Not opened yet'),
        },
        {
            key: 'reminder',
            label: t('Reminder'),
            value: dossier.next_reminder_at
                ? formatDate(dossier.next_reminder_at)
                : null,
            placeholder: t('No reminder scheduled'),
        },
        {
            key: 'due',
            label: t('Due'),
            value: dossier.due_date ? formatDate(dossier.due_date) : null,
            placeholder: t('No due date'),
        },
    ];

    return (
        <div className="space-y-3 px-4 py-3">
            <ol className="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-4 sm:gap-y-0">
                {marks.map((mark, index) => (
                    <li key={mark.key} className="min-w-0">
                        <span className="block truncate text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {mark.label}
                        </span>

                        {/*
                         * Connectors only from `sm` up, where all four marks share
                         * a row. Stacked two-up they would join marks that are not
                         * adjacent in the chase. The last mark carries none — the
                         * deadline ends the line rather than continuing it.
                         */}
                        <span className="relative mt-1.5 mb-1.5 hidden h-3 items-center sm:flex">
                            {index < marks.length - 1 ? (
                                <span
                                    aria-hidden="true"
                                    className="absolute top-1/2 -right-4 left-3.5 h-px -translate-y-1/2 bg-border"
                                />
                            ) : null}
                            <ChaseMarkGlyph
                                reached={mark.value !== null}
                                terminal={index === marks.length - 1}
                                daysRemaining={dossier.days_remaining}
                            />
                        </span>

                        <span
                            className={cn(
                                'block truncate font-data text-sm',
                                mark.value === null
                                    ? 'text-muted-foreground'
                                    : 'font-medium',
                            )}
                            title={mark.value ?? mark.placeholder}
                        >
                            {mark.value ?? mark.placeholder}
                        </span>

                        {mark.key === 'due' &&
                            dossier.days_remaining !== null && (
                                <DaysRemainingLabel
                                    daysRemaining={dossier.days_remaining}
                                    className="mt-0.5 text-xs"
                                />
                            )}
                    </li>
                ))}
            </ol>

            {/*
             * Standing configuration, not a moment in the chase. Two facts, so
             * two elements — owner and cadence are separately addressable rather
             * than one run of text joined by a separator.
             */}
            <p className="text-xs text-muted-foreground">
                <span>
                    {dossier.responsible_staff?.name ?? t('Unassigned')}
                </span>
                {' · '}
                <span>
                    {dossier.reminder_interval_days
                        ? t('reminder every :days days', {
                              days: dossier.reminder_interval_days,
                          })
                        : t('Automatic reminders off')}
                </span>
            </p>
        </div>
    );
}

/**
 * A reached moment is a filled mark; one that has not happened stays hollow, so
 * how far the chase has actually got is readable from the marks alone.
 *
 * The deadline is a stop rather than a dot — it ends the line instead of being
 * another step on it — and it picks up urgency colour only once it earns it.
 */
function ChaseMarkGlyph({
    reached,
    terminal,
    daysRemaining,
}: {
    reached: boolean;
    terminal: boolean;
    daysRemaining: number | null;
}) {
    if (terminal) {
        return (
            <span
                aria-hidden="true"
                className={cn(
                    'relative z-10 block h-4 w-[3px] rounded-full',
                    !reached || daysRemaining === null
                        ? 'bg-border'
                        : daysRemaining < 0
                          ? 'bg-destructive'
                          : daysRemaining === 0
                            ? 'bg-warning'
                            : 'bg-foreground',
                )}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'relative z-10 block size-2.5 rounded-full border',
                reached
                    ? 'border-foreground bg-foreground'
                    : 'border-border bg-background',
            )}
        />
    );
}
