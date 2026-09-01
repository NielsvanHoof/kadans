import { usePage } from "@inertiajs/react";
import {
    ClipboardList,
    FolderGit2,
    Images,
    LayoutGrid,
    LifeBuoy,
    ListChecks,
    ScrollText,
    ShieldCheck,
    ShieldQuestion,
    UserPlus,
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
} from "@/components/ui/sidebar";
import { usePermissions } from "@/hooks/use-permissions";
import { useTranslation } from "@/hooks/use-translation";
import { dashboard } from "@/routes";
import { index as platformSupportIndex } from "@/routes/platform/support";
import { dashboard as workspaceDashboard } from "@/routes/workspaces";
import { index as activityIndex } from "@/routes/workspaces/activity";
import { index as clientIndex } from "@/routes/workspaces/clients";
import { index as dataSubjectRequestIndex } from "@/routes/workspaces/data-subject-requests";
import { index as dossierIndex } from "@/routes/workspaces/dossiers";
import { index as mediaIndex } from "@/routes/workspaces/media";
import { index as membersIndex } from "@/routes/workspaces/members";
import { index as pipelineIndex } from "@/routes/workspaces/pipelines";
import { index as rolesIndex } from "@/routes/workspaces/roles";
import { index as templateIndex } from "@/routes/workspaces/templates";
import type { NavItem } from "@/types";

export function AppSidebar() {
    const { current_firm: currentFirm, auth } = usePage().props;
    const { can } = usePermissions();
    const { t } = useTranslation();
    const dashboardRoute = currentFirm ? workspaceDashboard(currentFirm) : dashboard();
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
                  ...(can("dossiers.view")
                      ? [
                            {
                                title: t("Dossiers"),
                                href: dossierIndex(currentFirm),
                                icon: FolderGit2,
                            },
                        ]
                      : []),
                  ...(can("clients.view")
                      ? [
                            {
                                title: t("Clients"),
                                href: clientIndex(currentFirm),
                                icon: Users,
                            },
                        ]
                      : []),
                  ...(can("members.view")
                      ? [
                            {
                                title: t("Members"),
                                href: membersIndex(currentFirm),
                                icon: UserPlus,
                            },
                        ]
                      : []),
                  ...(can("roles.view")
                      ? [
                            {
                                title: t("Roles & permissions"),
                                href: rolesIndex(currentFirm),
                                icon: ShieldCheck,
                            },
                        ]
                      : []),
                  ...(can("templates.view")
                      ? [
                            {
                                title: t("Templates"),
                                href: templateIndex(currentFirm),
                                icon: ClipboardList,
                            },
                        ]
                      : []),
                  ...(can("document_requests.view")
                      ? [
                            {
                                title: t("Media Library"),
                                href: mediaIndex(currentFirm),
                                icon: Images,
                            },
                        ]
                      : []),
                  ...(can("audit.view")
                      ? [
                            {
                                title: t("Activity"),
                                href: activityIndex(currentFirm),
                                icon: ScrollText,
                            },
                        ]
                      : []),
                  ...(can("data_subject.view")
                      ? [
                            {
                                title: t("Data-subject requests"),
                                href: dataSubjectRequestIndex(currentFirm),
                                icon: ShieldQuestion,
                            },
                        ]
                      : []),
                  ...(can("pipelines.view")
                      ? [
                            {
                                title: t("Pipelines"),
                                href: pipelineIndex(currentFirm),
                                icon: ListChecks,
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
