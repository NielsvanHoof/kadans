import { type ReactNode, useEffect, useState } from 'react';
import { useIndexQuery } from '@/hooks/use-index-query';
import { cn } from '@/lib/utils';

/**
 * Visits faster than this never show a loading state. Most filter visits
 * resolve well inside it, so the common case stays completely still.
 * Matches Inertia's own progress-bar delay so both appear together.
 */
const LOADING_DELAY_MS = 250;

/**
 * Wraps index list/pagination so slow filter visits fade the results,
 * not the toolbar.
 *
 * No spinner on purpose: Inertia's global progress bar (configured in app.tsx)
 * already signals the in-flight visit. This only softens the stale rows so it
 * is clear they are about to be replaced.
 */
export default function IndexQueryResults({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    // Pending only — filter mutations stay on IndexQueryToolbar.
    const { isPending } = useIndexQuery();
    const isSlow = useDelayedFlag(isPending, LOADING_DELAY_MS);

    return (
        <div
            className={cn(
                'transition-opacity duration-150 motion-reduce:transition-none',
                isSlow && 'pointer-events-none opacity-60',
                className,
            )}
            aria-busy={isPending || undefined}
        >
            {children}
        </div>
    );
}

/**
 * Mirrors `value` only once it has stayed true for `delayMs`.
 * Turning false is immediate so results snap back the moment they arrive.
 */
function useDelayedFlag(value: boolean, delayMs: number): boolean {
    const [delayed, setDelayed] = useState(false);

    useEffect(() => {
        if (!value) {
            setDelayed(false);
            return;
        }

        const timeout = setTimeout(() => setDelayed(true), delayMs);

        return () => clearTimeout(timeout);
    }, [value, delayMs]);

    return delayed;
}
