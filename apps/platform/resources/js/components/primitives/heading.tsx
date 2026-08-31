import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Single type ladder for page and section titles.
 *
 * - level 1 (page): text-2xl semibold
 * - level 2 (section): text-lg semibold
 * - variant="small": compact settings subsection (text-base medium)
 *
 * Prefer this over raw h1/h2 with ad-hoc sizes so the scale stays consistent.
 */
export default function Heading({
    title,
    description,
    badge,
    variant = 'default',
    level,
    className,
}: {
    title: string;
    description?: string;
    /** Optional status chip or similar, shown inline next to the title. */
    badge?: ReactNode;
    variant?: 'default' | 'small';
    /** Explicit heading level — page titles use 1, section titles use 2. */
    level: 1 | 2;
    className?: string;
}) {
    const TitleTag = level === 1 ? 'h1' : 'h2';

    // Page titles use the display serif; section titles stay in the UI sans.
    const titleClass =
        variant === 'small'
            ? 'mb-0.5 text-base font-medium tracking-tight'
            : level === 1
              ? 'font-display text-2xl font-semibold tracking-tight text-balance'
              : 'text-lg font-semibold tracking-tight text-balance';

    return (
        <header
            className={cn(
                variant === 'small' ? '' : 'mb-8 space-y-0.5',
                className,
            )}
        >
            {badge ? (
                <div className="flex flex-wrap items-center gap-2.5">
                    <TitleTag className={titleClass}>{title}</TitleTag>
                    {badge}
                </div>
            ) : (
                <TitleTag className={titleClass}>{title}</TitleTag>
            )}
            {description && (
                <p className="text-muted-foreground text-sm text-pretty">
                    {description}
                </p>
            )}
        </header>
    );
}
