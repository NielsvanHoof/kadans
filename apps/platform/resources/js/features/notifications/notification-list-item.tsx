import { Link, router } from '@inertiajs/react';
import {
    ClipboardCheck,
    type LucideIcon,
    Mic,
    Smartphone,
    Trash2,
} from 'lucide-react';
import { type MouseEvent, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Item } from '@/components/ui/item';
import type { WorkspaceNotification } from '@/features/notifications/types';
import type { Firm } from '@/features/workspaces/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import {
    destroy as destroyNotification,
    read as readNotification,
} from '@/routes/workspaces/notifications';

const TypeIcons: Record<string, LucideIcon> = {
    client_questionnaire_completed: ClipboardCheck,
    audio_transcription_completed: Mic,
    client_portal_new_device: Smartphone,
};

/**
 * Single inbox row — marks read then navigates to the target.
 * Separate delete control keeps the list from filling up indefinitely.
 */
export default function NotificationListItem({
    notification,
    workspace,
}: {
    notification: WorkspaceNotification;
    workspace: Firm;
}) {
    const { t } = useTranslation();
    const { formatDateTime, formatDateTimeWithZone, formatRelativeTime } =
        useFormatters();
    const isUnread = notification.read_at === null;
    const absoluteTime = notification.created_at
        ? formatDateTime(notification.created_at)
        : '';
    // The tooltip names the zone — on hover a bare wall-clock time is ambiguous.
    const absoluteTimeWithZone = notification.created_at
        ? formatDateTimeWithZone(notification.created_at)
        : '';
    // Relative labels depend on "now" — wait until mount to avoid hydration drift.
    const [relativeTime, setRelativeTime] = useState<string | null>(null);

    useEffect(() => {
        if (!notification.created_at) {
            return;
        }

        setRelativeTime(formatRelativeTime(notification.created_at));
        // formatRelativeTime is memoised on locale + timezone, so depending on it
        // covers both without re-running the effect on every render.
    }, [notification.created_at, formatRelativeTime]);

    const TypeIcon = TypeIcons[notification.type] ?? ClipboardCheck;
    const openNotification = (event: MouseEvent<Element>): void => {
        // Let modified / middle-clicks open the real URL without a mark-read hop.
        if (
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            event.button !== 0
        ) {
            return;
        }

        if (!isUnread) {
            return;
        }

        event.preventDefault();

        router.post(
            readNotification.url({
                tenant: workspace,
                notification: notification.id,
            }),
            {},
            {
                preserveScroll: true,
                only: ['notifications'],
                onSuccess: () => {
                    router.visit(notification.url);
                },
            },
        );
    };

    const deleteNotification = (): void => {
        router.delete(
            destroyNotification.url({
                tenant: workspace,
                notification: notification.id,
            }),
            {
                preserveScroll: true,
                only: ['notifications'],
            },
        );
    };

    return (
        <Item
            size="sm"
            className={cn(
                'group hover:bg-accent w-full flex-row items-start gap-2 rounded-none px-3 py-2.5 text-left',
                isUnread && 'bg-accent/40',
            )}
        >
            <Link
                href={notification.url}
                className="focus-visible:ring-ring/50 flex min-w-0 flex-1 items-start gap-2.5 outline-none focus-visible:ring-2"
                onClick={openNotification}
            >
                <span
                    className={cn(
                        'mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md',
                        isUnread
                            ? 'bg-primary/10 text-primary'
                            : 'bg-muted text-muted-foreground',
                    )}
                    aria-hidden="true"
                >
                    <TypeIcon className="size-3.5" />
                </span>
                <span className="min-w-0 flex-1">
                    <span
                        className={cn(
                            'line-clamp-2 leading-snug wrap-break-word',
                            isUnread
                                ? 'text-foreground font-medium'
                                : 'text-muted-foreground',
                        )}
                    >
                        {notification.message}
                    </span>
                    {notification.created_at && (
                        <span
                            className="font-data text-muted-foreground mt-0.5 block text-xs tabular-nums"
                            title={absoluteTimeWithZone}
                        >
                            {relativeTime ?? absoluteTime}
                        </span>
                    )}
                </span>
            </Link>
            {/* Always show on touch; reveal on hover/focus for pointer devices. */}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className={cn(
                    'text-muted-foreground hover:text-destructive size-7 shrink-0',
                    '[@media(hover:hover)]:opacity-0 [@media(hover:hover)]:group-focus-within:opacity-100 [@media(hover:hover)]:group-hover:opacity-100',
                )}
                aria-label={t('Delete notification')}
                onPress={deleteNotification}
            >
                <Trash2 className="size-3.5" aria-hidden="true" />
            </Button>
        </Item>
    );
}
