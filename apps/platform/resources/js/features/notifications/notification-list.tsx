import EmptyState from '@/components/primitives/empty-state';
import { ItemGroup } from '@/components/ui/item';
import NotificationListItem from '@/features/notifications/notification-list-item';
import type { WorkspaceNotification } from '@/features/notifications/types';
import type { Firm } from '@/features/workspaces/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Scrollable notification rows for the bell dropdown.
 */
export default function NotificationList({
    items,
    workspace,
}: {
    items: WorkspaceNotification[];
    workspace: Firm;
}) {
    const { t } = useTranslation();

    if (items.length === 0) {
        return (
            <EmptyState title={t('No notifications yet')} variant="compact" />
        );
    }

    return (
        <ItemGroup className="max-h-80 overflow-y-auto">
            {items.map((notification) => (
                <NotificationListItem
                    key={notification.id}
                    notification={notification}
                    workspace={workspace}
                />
            ))}
        </ItemGroup>
    );
}
