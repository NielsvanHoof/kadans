import { Deferred } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { AppContent } from '@/components/app-shell/app-content';
import { AppShell } from '@/components/app-shell/app-shell';
import { AppSidebar } from '@/components/app-shell/app-sidebar';
import { AppSidebarHeader } from '@/components/app-shell/app-sidebar-header';
import { Button } from '@/components/ui/button';
import NotificationBell from '@/features/notifications/notification-bell';
import { WorkspaceNotifications } from '@/features/notifications/use-workspace-notifications';
import SupportSessionBanner from '@/features/platform/support-session-banner';
import AppearanceMenu from '@/features/settings/appearance-menu';
import LocaleMenu from '@/features/settings/locale-menu';
import { useInertiaNavigationEffects } from '@/hooks/use-inertia-navigation-effects';
import { useTranslation } from '@/hooks/use-translation';
import type { AppLayoutProps } from '@/types';

/**
 * Closes the mobile sheet and resets focus on Inertia navigations.
 * Must render inside SidebarProvider (AppShell).
 */
function NavigationEffects(): null {
    useInertiaNavigationEffects();

    return null;
}

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { t } = useTranslation();

    return (
        <AppShell variant="sidebar">
            <NavigationEffects />
            <WorkspaceNotifications />
            <AppSidebar />
            <AppContent
                id="main-content"
                variant="sidebar"
                className="overflow-x-hidden"
                tabIndex={-1}
            >
                <SupportSessionBanner />
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    actions={
                        <>
                            {/* Theme + language sit beside the inbox bell. */}
                            <AppearanceMenu />
                            <LocaleMenu />
                            <Deferred
                                data="notifications"
                                fallback={
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="relative size-9"
                                        disabled
                                        aria-label={t('Notifications')}
                                    >
                                        <Bell className="size-5 opacity-50" />
                                    </Button>
                                }
                            >
                                <NotificationBell />
                            </Deferred>
                        </>
                    }
                />
                {children}
            </AppContent>
        </AppShell>
    );
}
