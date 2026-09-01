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
    items,
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
                        // An explicit flag lets a single entry (Admin) stay lit
                        // across sibling URLs it does not itself point at.
                        const isActive =
                            item.isActive ?? isCurrentOrParentUrl(item.href);

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
                                    /*
                                     * Coral wash plus a hard coral rail down the
                                     * left edge, overriding the vendored grey
                                     * `data-active` styles. Set here rather than
                                     * in ui/sidebar.tsx so `shadcn add sidebar
                                     * --overwrite` cannot silently revert it.
                                     *
                                     * The rail is an inset shadow, not a border,
                                     * so turning it on does not shift the label.
                                     */
                                    className="data-active:bg-brand-muted data-active:text-brand-foreground data-active:shadow-[inset_2px_0_0_0_var(--color-brand)]"
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
