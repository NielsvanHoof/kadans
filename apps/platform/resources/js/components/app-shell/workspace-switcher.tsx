import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown, Plus, Settings } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import { useTranslation } from '@/hooks/use-translation';
import { create as createFirm } from '@/routes/firms';
import { dashboard as workspaceDashboard } from '@/routes/workspaces';
import { index as settingsIndex } from '@/routes/workspaces/settings';

export function WorkspaceSwitcher() {
    const { current_firm: currentFirm, workspaces } = usePage().props;
    const { t } = useTranslation();
    const { can } = usePermissions();

    if (!currentFirm) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenuTrigger>
                    <SidebarMenuButton
                        size="lg"
                        className="group text-sidebar-accent-foreground data-open:bg-sidebar-accent"
                    >
                        <Building2 className="size-4" />
                        <span className="truncate font-medium">
                            {currentFirm.name}
                        </span>
                        <ChevronsUpDown className="ml-auto size-4" />
                    </SidebarMenuButton>
                    <DropdownMenu
                        className="min-w-56 rounded-lg"
                        placement="bottom end"
                    >
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>
                                {t('Your firms')}
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {workspaces.map((firm) => (
                                <DropdownMenuItem
                                    key={firm.slug}
                                    onAction={() => {
                                        if (firm.slug !== currentFirm.slug) {
                                            router.visit(
                                                workspaceDashboard.url(firm),
                                            );
                                        }
                                    }}
                                >
                                    <Building2 />
                                    <span className="truncate">
                                        {firm.name}
                                    </span>
                                    {firm.slug === currentFirm.slug && (
                                        <Check className="ml-auto" />
                                    )}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            {can('tenant.settings.manage') && (
                                <DropdownMenuItem
                                    href={settingsIndex(currentFirm).url}
                                >
                                    <Settings />
                                    <span>{t('Firm settings')}</span>
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem
                                onAction={() => router.visit(createFirm.url())}
                            >
                                <Plus />
                                <span>{t('Create new firm')}</span>
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    </DropdownMenu>
                </DropdownMenuTrigger>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
