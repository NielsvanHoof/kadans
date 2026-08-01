import { cn } from '@/lib/utils';

type LedgerFieldProps = {
    /**
     * `onPanel` sits on the ink brand panels (auth, portal header); `onSurface`
     * sits on bone or card. Only the alpha differs — the rules have to stay at
     * the threshold of visible on both.
     */
    tone?: 'onPanel' | 'onSurface';
    className?: string;
};

/**
 * The branded backdrop: ruled ledger paper.
 *
 * Horizontal rules on a 2rem rhythm with a single brass margin rule down the
 * left, which is what an accountant's working paper actually looks like. It
 * replaces the old stamped-on logo wash — texture carries the brand, so it
 * never competes with the content sitting on top.
 *
 * Kept deliberately at the threshold of visible. It should register as paper
 * stock when you look for it and disappear when you're reading.
 *
 * Decorative only, and drawn entirely in CSS so there is no asset to ship.
 */
export default function LedgerField({
    tone = 'onPanel',
    className,
}: LedgerFieldProps) {
    const onPanel = tone === 'onPanel';

    return (
        <div
            aria-hidden
            className={cn(
                'pointer-events-none absolute inset-0 overflow-hidden',
                onPanel ? 'text-brand-panel-foreground' : 'text-foreground',
                className,
            )}
        >
            <div
                className="absolute inset-0"
                style={{
                    // Both stops of the rule sit at the same offset, so it is a
                    // hard 1px line. With a single stop the browser interpolates
                    // across that pixel and the rule fades out to nothing.
                    backgroundImage:
                        'repeating-linear-gradient(to bottom, transparent 0, transparent calc(2rem - 1px), currentColor calc(2rem - 1px), currentColor 2rem)',
                    opacity: onPanel ? 0.07 : 0.045,
                }}
            />

            {/* The margin rule, always brass, always at the same inset. */}
            <div
                className={cn(
                    'absolute inset-y-0 left-10 w-px',
                    onPanel ? 'bg-warning/45' : 'bg-warning/25',
                )}
            />
        </div>
    );
}
