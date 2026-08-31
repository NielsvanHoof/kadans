import type { ReactNode } from 'react';
import { Breadcrumbs } from '@/components/app-shell/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    actions,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    /** Right-side actions (e.g. notification bell) — passed from the layout. */
    actions?: ReactNode;
}) {
    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {actions ? (
                <div className="ml-auto flex items-center gap-2">{actions}</div>
            ) : null}
        </header>
    );
}
