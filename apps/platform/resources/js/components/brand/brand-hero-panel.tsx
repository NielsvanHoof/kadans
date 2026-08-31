import type { ReactNode } from 'react';
import LedgerField from '@/components/brand/ledger-field';
import { cn } from '@/lib/utils';

type BrandHeroPanelProps = {
    children: ReactNode;
    className?: string;
};

/**
 * Coral panel on ruled ledger paper — the brand surface for the signed-out
 * half of the app (auth, onboarding).
 *
 * Uses `brand-panel` rather than `primary` on purpose: this is a text-heavy
 * printed surface that needs AA headroom. Display coral (#EF5734) is only
 * 3.45:1 with white; the burnt panel depth keeps body copy readable in both
 * themes.
 */
export default function BrandHeroPanel({
    children,
    className,
}: BrandHeroPanelProps) {
    return (
        <div
            className={cn(
                'bg-brand-panel text-brand-panel-foreground relative isolate overflow-hidden',
                className,
            )}
        >
            <LedgerField tone="onPanel" />
            <div className="relative z-10 flex h-full flex-col">{children}</div>
        </div>
    );
}
