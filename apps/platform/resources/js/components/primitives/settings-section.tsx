import type { ReactNode } from 'react';
import Heading from '@/components/primitives/heading';
import { cn } from '@/lib/utils';

/**
 * Settings page section scaffold — compact heading plus content.
 * Used by 2FA, passkeys, delete-user, and similar security blocks.
 */
export default function SettingsSection({
    title,
    description,
    children,
    className,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('space-y-6', className)}>
            <Heading
                level={2}
                variant="small"
                title={title}
                description={description}
            />
            {children}
        </section>
    );
}
