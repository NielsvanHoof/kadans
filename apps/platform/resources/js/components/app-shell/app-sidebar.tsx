import { usePage } from "@inertiajs/react";
import {
    ClipboardList,
    FolderGit2,
    Images,
    LayoutGrid,
    LifeBuoy,
    ListChecks,
    ScrollText,
    Shield,
    Users,
} from "lucide-react";
import AppLogo from "@/components/app-shell/app-logo";
import { NavMain } from "@/components/app-shell/nav-main";
import { NavUser } from "@/components/app-shell/nav-user";
import { WorkspaceSwitcher } from "@/components/app-shell/workspace-switcher";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermissions } from '@/hooks/use-permissions';
import { useTranslation } from '@/hooks/use-translation';
import { useAdminSections } from '@/layouts/admin/admin-sections';
import { dashboard } from '@/routes';
import { index as platformSupportIndex } from '@/routes/platform/support';
import { dashboard as workspaceDashboard } from '@/routes/workspaces';
import { index as activityIndex } from '@/routes/workspaces/activity';
import { index as clientIndex } from '@/routes/workspaces/clients';
import { index as dossierIndex } from '@/routes/workspaces/dossiers';
import { index as mediaIndex } from '@/routes/workspaces/media';
import { index as pipelineIndex } from '@/routes/workspaces/pipelines';
import { index as templateIndex } from '@/routes/workspaces/templates';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { current_firm: currentFirm, auth } = usePage().props;
    const { can } = usePermissions();
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useTranslation();
    const adminSections = useAdminSections();
    const dashboardRoute = currentFirm
        ? workspaceDashboard(currentFirm)
        : dashboard();
    const isPlatformSupport = auth.user?.is_platform_support === true;

    const mainNavItems: NavItem[] = [
        {
            title: t("Dashboard"),
            href: dashboardRoute,
            icon: LayoutGrid,
        },
        ...(isPlatformSupport
            ? [
                  {
                      title: t("Platform support"),
                      href: platformSupportIndex(),
                      icon: LifeBuoy,
                  },
              ]
            : []),
        ...(currentFirm
            ? [
                  {
                      title: t('Dossiers'),
                      href: dossierIndex(currentFirm),
                      icon: FolderGit2,
                  },
                  {
                      title: t('Clients'),
                      href: clientIndex(currentFirm),
                      icon: Users,
                  },
                  {
                      title: t('Templates'),
                      href: templateIndex(currentFirm),
                      icon: ClipboardList,
                  },
                  {
                      title: t('Media Library'),
                      href: mediaIndex(currentFirm),
                      icon: Images,
                  },
                  {
                      title: t('Activity'),
                      href: activityIndex(currentFirm),
                      icon: ScrollText,
                  },
                  ...(can('pipelines.view')
                      ? [
                            {
                                title: t('Pipelines'),
                                href: pipelineIndex(currentFirm),
                                icon: ListChecks,
                            },
                        ]
                      : []),
                  ...(can('tenant.admin.access') && adminSections.length > 0
                      ? [
                            {
                                title: t('Admin'),
                                href: adminSections[0].href,
                                icon: Shield,
                                isActive: adminSections.some((section) =>
                                    isCurrentOrParentUrl(section.href),
                                ),
                            },
                        ]
                      : []),
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" href={dashboardRoute.url}>
                            <AppLogo />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <WorkspaceSwitcher />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
