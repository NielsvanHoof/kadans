import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useSidebar } from '@/components/ui/sidebar';

/**
 * On every Inertia visit:
 * 1. Close the mobile sidebar sheet so it does not stay open over the new page.
 * 2. Move focus to #main-content so keyboard users are not parked in the nav.
 */
export function useInertiaNavigationEffects(): void {
    const { isMobile, setOpenMobile } = useSidebar();

    useEffect(() => {
        return router.on('navigate', () => {
            if (isMobile) {
                setOpenMobile(false);
            }

            // Defer so the new page content is in the DOM before focusing.
            requestAnimationFrame(() => {
                const main = document.getElementById('main-content');

                if (main instanceof HTMLElement) {
                    main.focus({ preventScroll: true });
                }
            });
        });
    }, [isMobile, setOpenMobile]);
}
