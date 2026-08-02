import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import type { NavItem } from '@/types';

export function NavMain({
    items = [],
    label,
}: {
    items: NavItem[];
    label?: string;
}) {
    const { t } = useTranslation();
    // Prefix match so /dossiers/{id} keeps "Dossiers" highlighted.
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const groupLabel = label ?? t('Platform');

    return (
        <nav aria-label={groupLabel}>
            <SidebarGroup className="px-2 py-0">
                <SidebarGroupLabel>{groupLabel}</SidebarGroupLabel>
                <SidebarMenu>
                    {items.map((item) => {
                        const isActive = isCurrentOrParentUrl(item.href);

                        return (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    href={
                                        typeof item.href === 'string'
                                            ? item.href
                                            : item.href.url
                                    }
                                    isActive={isActive}
                                    tooltip={{ children: item.title }}
                                    aria-current={isActive ? 'page' : undefined}
                                >
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </SidebarGroup>
        </nav>
    );
}
