import type { ReactNode } from 'react';
import LedgerField from '@/components/brand/ledger-field';
import { cn } from '@/lib/utils';

type BrandHeroPanelProps = {
    children: ReactNode;
    className?: string;
};

/**
 * Ink panel on ruled ledger paper — the brand surface for the signed-out half
 * of the app (auth, onboarding).
 *
 * Uses `brand-panel` rather than `primary` on purpose: this is a printed ink
 * surface and has to stay dark in both themes. `--primary` inverts to bone in
 * dark mode, which would turn the panel inside out.
 */
export default function BrandHeroPanel({
    children,
    className,
}: BrandHeroPanelProps) {
    return (
        <div
            className={cn(
                'relative isolate overflow-hidden bg-brand-panel text-brand-panel-foreground',
                className,
            )}
        >
            <LedgerField tone="onPanel" />
            <div className="relative z-10 flex h-full flex-col">{children}</div>
        </div>
    );
}
