import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Shared bordered, divided list shell used across index cards.
 * Keeps divide-y / border styling consistent without each card reinventing it.
 */
export default function IndexList({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('divide-y rounded-md border', className)}>
            {children}
        </div>
    );
}
