import { CircleAlert } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * How long is left on a due date, in words.
 *
 * The number is signed server-side (negative once the date has passed), so this
 * never redoes date maths in the browser — calendar days are deliberately not
 * zone-converted, and recomputing here would reintroduce the drift.
 *
 * Overdue carries an icon as well as a colour: status must not be conveyed by
 * colour alone.
 */
export default function DaysRemainingLabel({
    daysRemaining,
    className,
}: {
    daysRemaining: number;
    className?: string;
}) {
    const { t } = useTranslation();

    if (daysRemaining < 0) {
        const daysOverdue = Math.abs(daysRemaining);

        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1 font-medium text-destructive',
                    className,
                )}
            >
                <CircleAlert className="size-3.5 shrink-0" aria-hidden />
                {daysOverdue === 1
                    ? t('1 day overdue')
                    : t(':count days overdue', { count: daysOverdue })}
            </span>
        );
    }

    if (daysRemaining === 0) {
        return (
            <span
                className={cn('font-medium text-warning-foreground', className)}
            >
                {t('Due today')}
            </span>
        );
    }

    return (
        <span className={cn('text-muted-foreground', className)}>
            {daysRemaining === 1
                ? t('Due tomorrow')
                : t('Due in :count days', { count: daysRemaining })}
        </span>
    );
}
