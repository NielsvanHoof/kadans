import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { KeyRound, MailQuestion, MessageSquareOff } from 'lucide-react';
import EmptyState from '@/components/primitives/empty-state';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    DashboardFollowUpItem,
    DashboardFollowUpSignals,
} from '@/features/dashboard/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { show as showDossier } from '@/routes/workspaces/dossiers';

type SignalGroup = {
    key: string;
    label: string;
    icon: LucideIcon;
    tone: string;
    items: readonly DashboardFollowUpItem[];
    caption: (days: number | null) => string;
};

/**
 * Where the client side of the workflow has stalled.
 *
 * The counters elsewhere say how much work is outstanding; this says which of
 * it is waiting on someone who has gone silent, which is the part a person can
 * actually do something about today.
 */
export default function FollowUpSignalsCard({
    signals,
}: {
    signals: DashboardFollowUpSignals;
}) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();

    const groups: SignalGroup[] = [
        {
            key: 'never_opened',
            label: t('Invited but never opened'),
            icon: MailQuestion,
            tone: 'text-warning-foreground bg-warning-muted',
            items: signals.never_opened,
            caption: (days) =>
                days === null
                    ? t('Invitation sent')
                    : t('Invited :count days ago', { count: days }),
        },
        {
            key: 'gone_quiet',
            label: t('No client activity'),
            icon: MessageSquareOff,
            tone: 'text-muted-foreground bg-muted',
            items: signals.gone_quiet,
            caption: (days) =>
                days === null
                    ? t('No recent activity')
                    : t('Last opened :count days ago', { count: days }),
        },
        {
            key: 'expiring_links',
            label: t('Portal links expiring soon'),
            icon: KeyRound,
            tone: 'text-primary bg-primary/10',
            items: signals.expiring_links,
            caption: (days) => {
                if (days === null) {
                    return t('Expiring soon');
                }

                if (days <= 0) {
                    return t('Expires today');
                }

                return days === 1
                    ? t('Expires tomorrow')
                    : t('Expires in :count days', { count: days });
            },
        },
    ];

    const activeGroups = groups.filter((group) => group.items.length > 0);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Client follow-up')}</CardTitle>
                <CardDescription>
                    {t('Clients who need a nudge before work can continue.')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {activeGroups.length === 0 ? (
                    <EmptyState
                        variant="compact"
                        title={t('No follow-up needed')}
                        description={t(
                            'Every client with outstanding work has been in touch recently.',
                        )}
                    />
                ) : (
                    activeGroups.map((group) => (
                        <div key={group.key} className="space-y-1.5">
                            <p className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                <span
                                    className={cn(
                                        'flex size-6 shrink-0 items-center justify-center rounded-md',
                                        group.tone,
                                    )}
                                >
                                    <group.icon
                                        className="size-3.5"
                                        aria-hidden
                                    />
                                </span>
                                {group.label}
                            </p>

                            <ul className="space-y-1.5">
                                {group.items.map((item) => (
                                    <li key={`${group.key}-${item.id}`}>
                                        <Link
                                            href={showDossier({
                                                tenant: currentWorkspace,
                                                dossier: item.id,
                                            })}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium">
                                                    {item.title}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {item.client_name}
                                                </span>
                                            </span>
                                            <span className="font-data text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                                {group.caption(item.days)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}
