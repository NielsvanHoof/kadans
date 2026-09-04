import { Deferred } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import AppContent from '@/components/app-shell/app-content';
import AppShell from '@/components/app-shell/app-shell';
import AppSidebar from '@/components/app-shell/app-sidebar';
import AppSidebarHeader from '@/components/app-shell/app-sidebar-header';
import SkipToMainContent from '@/components/primitives/skip-to-main-content';
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
            {/* First tab stop on first load — navigate hook only helps after client visits. */}
            <SkipToMainContent />
            <NavigationEffects />
            <WorkspaceNotifications />
            <AppSidebar />
            {/* min-w-0, not overflow-x-hidden. SidebarInset is a flex item in
                a row container without min-w-0 of its own, and `min-width:auto`
                only resolves to the content-based minimum when overflow is
                visible — so overflow-x-hidden was standing in for min-w-0 by
                accident. Both stop the page scrolling sideways, but min-w-0
                lets inner overflow-x-auto regions scroll instead of clipping,
                so a wide table stays reachable rather than silently cut off. */}
            <AppContent
                id="main-content"
                variant="sidebar"
                className="min-w-0"
                tabIndex={-1}
            >
                <SupportSessionBanner />
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    actions={
                        <>
                            <Deferred
                                data="notifications"
                                fallback={
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="relative size-9"
                                        isDisabled
                                        aria-label={t('Notifications')}
                                    >
                                        <Bell className="size-5 opacity-50" />
                                    </Button>
                                }
                            >
                                <NotificationBell />
                            </Deferred>
                            <AppearanceMenu />
                            <LocaleMenu />
                        </>
                    }
                />
                {children}
            </AppContent>
        </AppShell>
    );
}
