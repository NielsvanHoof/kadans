import type { SVGAttributes } from 'react';

/**
 * The Qompose mark: a ledger ring whose Q-tail is a full audit tick.
 *
 * The same tick an accountant writes in the margin next to an item they have
 * checked off — so the logo and the accepted-status glyph are one gesture at
 * two sizes. See `components/brand/audit-mark.tsx`.
 *
 * Drawn on a 32 grid with round caps so it holds together down to 16px.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 32 32"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            stroke="currentColor"
            strokeWidth={3.85}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle cx="12.6" cy="14.54" r="9.27" />
            <path d="M16.95 22.73L21.71 26.73L28.68 17.82" />
        </svg>
    );
}
