import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import NotificationList from '@/features/notifications/notification-list';
import { useTranslation } from '@/hooks/use-translation';
import { readAll as readAllNotifications } from '@/routes/workspaces/notifications';

/**
 * Top-bar bell with unread badge and inbox dropdown.
 * Only renders inside a workspace (current_firm + notifications shared props).
 *
 * React Aria: PopoverTrigger is the root (DialogTrigger). Trigger button and
 * Popover content are siblings — not the old Radix Popover > Trigger > Content tree.
 */
export default function NotificationBell() {
    const { current_firm: currentFirm, notifications } = usePage().props;
    const { t } = useTranslation();

    if (!currentFirm || !notifications) {
        return null;
    }

    const unreadCount = notifications.unread_count;

    const markAllRead = (): void => {
        if (unreadCount === 0) {
            return;
        }

        router.post(
            readAllNotifications.url(currentFirm),
            {},
            {
                preserveScroll: true,
                only: ['notifications'],
            },
        );
    };

    return (
        <PopoverTrigger>
            <Button
                variant="ghost"
                size="icon"
                className="relative size-9"
                aria-label={
                    unreadCount > 0
                        ? t('Notifications, :count unread', {
                              count: unreadCount,
                          })
                        : t('Notifications')
                }
            >
                <Bell className="size-5" aria-hidden="true" />
                {unreadCount > 0 && (
                    <Badge
                        variant="destructive"
                        className="absolute -top-0.5 -right-0.5 h-5 min-w-5 justify-center rounded-full px-1 text-xs leading-none"
                    >
                        {unreadCount > 99 ? '99+' : unreadCount}
                    </Badge>
                )}
            </Button>
            <Popover className="w-[min(20rem,calc(100vw-2rem))] p-0">
                <PopoverHeader className="flex-row items-center justify-between gap-2 px-3 py-2">
                    <PopoverTitle>{t('Notifications')}</PopoverTitle>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-xs"
                        isDisabled={unreadCount === 0}
                        onPress={markAllRead}
                    >
                        {t('Mark all read')}
                    </Button>
                </PopoverHeader>
                <Separator />
                <NotificationList
                    items={notifications.items}
                    workspace={currentFirm}
                />
            </Popover>
        </PopoverTrigger>
    );
}
