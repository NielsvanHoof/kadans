import { cn } from '@/lib/utils';

/**
 * The four marks an accountant writes in the margin of a checklist.
 *
 * - `open` — nothing written yet; the item is still blank
 * - `flagged` — in the tray, waiting to be looked at
 * - `ticked` — checked off; the same tick as the Qompose mark
 * - `queried` — struck out and sent back
 */
export type AuditMarkVariant = 'open' | 'flagged' | 'ticked' | 'queried';

/**
 * Tone is separate from variant so the mark can sit inside something that
 * already sets a colour (a Badge, a button) without fighting it.
 */
export type AuditMarkTone = 'status' | 'inherit';

const TONE_CLASSES: Record<AuditMarkVariant, string> = {
    open: 'text-muted-foreground',
    flagged: 'text-warning',
    ticked: 'text-success',
    queried: 'text-destructive',
};

/**
 * The signature glyph of the interface: the audit mark.
 *
 * Every document request carries one in the left gutter of its row, the way an
 * accountant annotates a checklist. Shape does the work — the four variants are
 * distinguishable with colour stripped out, which matters because a reviewer
 * scanning forty rows reads the column, not the labels.
 *
 * Purely decorative: the row always states its status in words as well, so this
 * is `aria-hidden` and never the only carrier of meaning.
 */
export default function AuditMark({
    variant,
    tone = 'status',
    className,
}: {
    variant: AuditMarkVariant;
    tone?: AuditMarkTone;
    className?: string;
}) {
    return (
        <svg
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            stroke="currentColor"
            strokeWidth={2.4}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className={cn(
                'size-4 shrink-0',
                tone === 'status' && TONE_CLASSES[variant],
                className,
            )}
        >
            {variant === 'open' && <circle cx="12" cy="12" r="8" />}

            {variant === 'flagged' && (
                <>
                    <circle cx="12" cy="12" r="8" />
                    <circle cx="12" cy="12" r="2.6" fill="currentColor" />
                </>
            )}

            {/* Scaled from the logo tick so the two read as one gesture. */}
            {variant === 'ticked' && (
                <path d="M3.03 12.70L10.31 18.82L20.97 5.19" />
            )}

            {/* Rotated a few degrees so it reads as a struck-out annotation
                rather than a UI close button. */}
            {variant === 'queried' && (
                <g transform="rotate(-6 12 12)">
                    <path d="M7.2 7.2L16.8 16.8" />
                    <path d="M16.8 7.2L7.2 16.8" />
                </g>
            )}
        </svg>
    );
}
