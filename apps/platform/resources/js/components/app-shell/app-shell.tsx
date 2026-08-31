import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
    /** Merged onto the header-variant shell wrapper (e.g. h-dvh for fullscreen pages). */
    className?: string;
};

export function AppShell({ children, variant = 'sidebar', className }: Props) {
    const isOpen = usePage().props.sidebar_open;

    if (variant === 'header') {
        return (
            // min-h-svh, matching the sidebar variant: on mobile browsers 100vh
            // includes the collapsing toolbar, so min-h-screen leaves a gap
            // once it retracts.
            <div className={cn('flex min-h-svh w-full flex-col', className)}>
                {children}
            </div>
        );
    }

    return <SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>;
}
