import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type TextLinkProps = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: TextLinkProps) {
    return (
        <Link
            className={cn(
                // Darkened coral for body-sized links — display #EF5734 is only
                // 3.45:1 on the canvas (same split as qompose.nl vs AA).
                'text-brand-foreground decoration-brand-border underline underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
