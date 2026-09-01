import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    CircleAlert,
    FolderOpen,
    ScanSearch,
} from 'lucide-react';
import DaysRemainingLabel from '@/components/primitives/days-remaining-label';
import EmptyState from '@/components/primitives/empty-state';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { DashboardMyWork } from '@/features/dashboard/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import {
    index as dossierIndex,
    show as showDossier,
} from '@/routes/workspaces/dossiers';

/**
 * The signed-in user's own queue: three counters and a short dated agenda.
 *
 * Every other widget on the dashboard is firm-wide, so this is the only place
 * that answers "what is mine".
 */
export default function MyWorkCard({ myWork }: { myWork: DashboardMyWork }) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();

    const stats = [
        {
            key: 'assigned',
            label: t('Assigned to you'),
            count: myWork.assigned_open,
            icon: FolderOpen,
            tone: 'text-muted-foreground bg-muted',
        },
        {
            key: 'review',
            label: t('Awaiting your review'),
            count: myWork.awaiting_my_review,
            icon: ScanSearch,
            tone: 'text-primary bg-primary/10',
        },
        {
            key: 'overdue',
            label: t('Overdue'),
            count: myWork.overdue,
            icon: CircleAlert,
            tone: 'text-destructive bg-destructive/10',
        },
    ] as const;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Your work')}</CardTitle>
                <CardDescription>
                    {t('Dossiers you are responsible for.')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-2 sm:grid-cols-3">
                    {stats.map((stat) => (
                        <div
                            key={stat.key}
                            className="rounded-lg border px-3 py-2"
                        >
                            <span className="text-muted-foreground flex items-center gap-2 text-xs">
                                <span
                                    className={cn(
                                        'flex size-6 shrink-0 items-center justify-center rounded-md',
                                        stat.tone,
                                    )}
                                >
                                    <stat.icon
                                        className="size-3.5"
                                        aria-hidden
                                    />
                                </span>
                                {stat.label}
                            </span>
                            <p className="font-data mt-1 text-2xl font-semibold tabular-nums">
                                {stat.count}
                            </p>
                        </div>
                    ))}
                </div>

                {myWork.assigned_open === 0 ? (
                    <EmptyState
                        variant="compact"
                        title={t('Nothing is assigned to you right now.')}
                        description={t(
                            "No dossiers assigned to you yet. When you're set as responsible, they'll show up here.",
                        )}
                    />
                ) : (
                    <div className="space-y-2">
                        <p className="text-muted-foreground text-xs font-medium">
                            {t('Next due')}
                        </p>

                        {myWork.agenda.length === 0 ? (
                            <p className="text-muted-foreground rounded-lg border border-dashed px-3 py-3 text-sm">
                                {t(
                                    'Nothing of yours is due in the next 7 days.',
                                )}
                            </p>
                        ) : (
                            <ul className="space-y-1.5">
                                {myWork.agenda.map((item) => (
                                    <li key={item.id}>
                                        <Link
                                            href={showDossier({
                                                tenant: currentWorkspace,
                                                dossier: item.id,
                                            })}
                                            className="hover:bg-muted/50 focus-visible:ring-ring flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium">
                                                    {item.title}
                                                </span>
                                                <span className="text-muted-foreground block truncate text-xs">
                                                    {item.client_name}
                                                </span>
                                            </span>
                                            <DaysRemainingLabel
                                                daysRemaining={
                                                    item.days_remaining
                                                }
                                                className="text-xs"
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Link
                            href={dossierIndex(currentWorkspace, {
                                query: {
                                    filter: { queue: 'overdue' },
                                },
                            })}
                            className="text-muted-foreground focus-visible:ring-ring inline-flex items-center gap-1.5 text-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <CalendarClock className="size-3.5" aria-hidden />
                            {t('See everything overdue')}
                        </Link>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
