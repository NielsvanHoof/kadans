import type { ReactNode } from 'react';
import { useId } from 'react';

/**
 * A titled band of the dashboard.
 *
 * The page reads as three answers — what is mine, what needs attention, how the
 * firm is doing — so each band is a labelled landmark rather than another card.
 * Heading classes match the existing metrics grid so no lane looks foreign.
 */
export default function DashboardLane({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2
                        id={headingId}
                        className="font-display text-lg font-semibold tracking-tight"
                    >
                        {title}
                    </h2>
                    {description && (
                        <p className="text-muted-foreground text-sm">
                            {description}
                        </p>
                    )}
                </div>
                {action}
            </div>

            {children}
        </section>
    );
}
