import { router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    Popover,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipTrigger } from '@/components/ui/tooltip';
import NotificationList from '@/features/notifications/notification-list';
import { useTranslation } from '@/hooks/use-translation';
import {
    clearAll as clearAllNotifications,
    readAll as readAllNotifications,
} from '@/routes/workspaces/notifications';

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
    // Controlled so Clear all can open from an icon button without nesting triggers.
    const [clearOpen, setClearOpen] = useState(false);

    if (!currentFirm || !notifications) {
        return null;
    }

    const unreadCount = notifications.unread_count;
    const itemCount = notifications.items.length;

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
        <>
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
                            className="absolute -top-0.5 -right-0.5 h-5 min-w-5 justify-center rounded-full px-1 text-xs leading-none tabular-nums"
                        >
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </Badge>
                    )}
                </Button>
                <Popover className="w-[min(22rem,calc(100vw-2rem))] overflow-hidden p-0">
                    {/* Icon actions keep long locales (NL) inside the panel. */}
                    <PopoverHeader className="flex-row items-center justify-between gap-2 px-3 py-2">
                        <div className="flex min-w-0 items-center gap-2">
                            <PopoverTitle className="text-pretty">
                                {t('Notifications')}
                            </PopoverTitle>
                            {unreadCount > 0 && (
                                <Badge
                                    variant="secondary"
                                    className="h-5 min-w-5 justify-center rounded-full px-1.5 text-xs tabular-nums"
                                >
                                    {unreadCount > 99 ? '99+' : unreadCount}
                                </Badge>
                            )}
                        </div>
                        <ButtonGroup className="shrink-0">
                            <TooltipTrigger>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    isDisabled={unreadCount === 0}
                                    aria-label={t('Mark all read')}
                                    onPress={markAllRead}
                                >
                                    <CheckCheck
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </Button>
                                <Tooltip>{t('Mark all read')}</Tooltip>
                            </TooltipTrigger>
                            <TooltipTrigger>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    isDisabled={itemCount === 0}
                                    aria-label={t('Clear all')}
                                    onPress={() => setClearOpen(true)}
                                >
                                    <Trash2
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </Button>
                                <Tooltip>{t('Clear all')}</Tooltip>
                            </TooltipTrigger>
                        </ButtonGroup>
                    </PopoverHeader>
                    <Separator />
                    <NotificationList
                        items={notifications.items}
                        workspace={currentFirm}
                    />
                </Popover>
            </PopoverTrigger>

            <ConfirmDestroyDialog
                open={clearOpen}
                onOpenChange={setClearOpen}
                title={t('Clear all notifications?')}
                description={t(
                    'This removes every notification for this workspace from your inbox. You cannot undo this.',
                )}
                confirmLabel={t('Clear all')}
                form={clearAllNotifications.form(currentFirm)}
                options={{
                    preserveScroll: true,
                    only: ['notifications'],
                }}
            />
        </>
    );
}
