import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { UserInfo } from '@/components/app-shell/user-info';
import { UserMenuContent } from '@/components/app-shell/user-menu-content';
import {
    DropdownMenu,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

export function NavUser() {
    const { auth } = usePage().props;

    if (!auth.user) {
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
                        <UserInfo user={auth.user} />
                        <ChevronsUpDown className="ml-auto size-4" />
                    </SidebarMenuButton>
                    <DropdownMenu
                        className="min-w-56 rounded-lg"
                        placement="bottom end"
                    >
                        <UserMenuContent user={auth.user} />
                    </DropdownMenu>
                </DropdownMenuTrigger>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
