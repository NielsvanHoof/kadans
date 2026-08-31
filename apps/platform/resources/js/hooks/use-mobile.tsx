import { useMediaQuery } from 'usehooks-ts';

const MOBILE_BREAKPOINT = 768;

/**
 * Matches shadcn sidebar mobile breakpoint (viewport width below 768px).
 *
 * `initializeWithValue` reads matchMedia during the first render instead of
 * waiting for the effect. Without it the hook returns false on that render, so
 * a phone paints the desktop sidebar branch for one frame before swapping to
 * the drawer — the sidebar picks its branch in JS, not CSS, so the flash is
 * a real DOM swap rather than a repaint.
 */
export function useIsMobile(): boolean {
    return useMediaQuery(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`, {
        defaultValue: false,
        initializeWithValue: true,
    });
}
