import { Inbox, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { cn } from '@/lib/utils';

type EmptyStateVariant = 'inline' | 'compact' | 'panel';

type EmptyStateProps = {
    title: string;
    description?: string;
    icon?: LucideIcon;
    action?: ReactNode;
    variant?: EmptyStateVariant;
    bordered?: boolean;
    className?: string;
};

/**
 * Shared empty-data placeholder for lists, cards, and panels.
 */
export default function EmptyState({
    title,
    description,
    icon: Icon = Inbox,
    action,
    variant = 'inline',
    bordered = false,
    className,
}: EmptyStateProps) {
    if (variant === 'compact') {
        return (
            <Empty
                className={cn(
                    'flex-none gap-4 rounded-none px-3 py-8 md:p-8',
                    className,
                )}
            >
                <EmptyHeader className="gap-1">
                    <EmptyTitle className="text-sm">{title}</EmptyTitle>
                    {description && (
                        <EmptyDescription>{description}</EmptyDescription>
                    )}
                </EmptyHeader>
                {action && <EmptyContent>{action}</EmptyContent>}
            </Empty>
        );
    }

    if (variant === 'panel') {
        return (
            <Empty
                className={cn(
                    'flex-none gap-4 p-8 md:p-8',
                    bordered && 'rounded-lg border',
                    className,
                )}
            >
                <EmptyMedia variant="icon" className="mb-0 size-14 rounded-2xl">
                    <Icon
                        className="text-muted-foreground size-7"
                        aria-hidden
                    />
                </EmptyMedia>
                <EmptyHeader className="gap-1">
                    <EmptyTitle className="text-base">{title}</EmptyTitle>
                    {description && (
                        <EmptyDescription>{description}</EmptyDescription>
                    )}
                </EmptyHeader>
                {action && <EmptyContent>{action}</EmptyContent>}
            </Empty>
        );
    }

    return (
        <Empty
            className={cn(
                'flex-none items-start justify-start gap-4 rounded-none p-0 text-left md:p-0',
                bordered && 'rounded-md border px-4 py-3 md:px-4 md:py-3',
                className,
            )}
        >
            <EmptyHeader className="max-w-none items-start gap-1 text-left">
                <EmptyTitle className="text-muted-foreground text-sm font-normal">
                    {title}
                </EmptyTitle>
                {description && (
                    <EmptyDescription className="text-left">
                        {description}
                    </EmptyDescription>
                )}
            </EmptyHeader>
            {action && (
                <EmptyContent className="items-start">{action}</EmptyContent>
            )}
        </Empty>
    );
}
