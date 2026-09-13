import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const routerPost = vi.fn();
const routerFlushAll = vi.fn();
const cleanup = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: routerPost,
        flushAll: routerFlushAll,
    },
    usePage: () =>
        mockInertiaPage({
            translations: {},
            locale: 'en',
            available_locales: [],
        }),
}));

vi.mock('@/hooks/use-mobile-navigation', () => ({
    useMobileNavigation: () => cleanup,
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/routes', () => ({
    logout: Object.assign(() => ({ url: '/logout', method: 'post' as const }), {
        url: () => '/logout',
    }),
}));

vi.mock('@/routes/profile', () => ({
    edit: () => ({ url: '/settings/profile' }),
}));

vi.mock('@/components/app-shell/user-info', () => ({
    default: () => <div>User info</div>,
}));

const { default: UserMenuContent } =
    await import('@/components/app-shell/user-menu-content');

describe('UserMenuContent', () => {
    beforeEach(() => {
        routerPost.mockReset();
        routerFlushAll.mockReset();
        cleanup.mockReset();
    });

    it('posts to logout after cleanup and cache flush', async () => {
        const user = userEvent.setup();

        render(
            <DropdownMenuTrigger>
                <Button aria-label="User menu">User</Button>
                <DropdownMenu>
                    <UserMenuContent
                        user={
                            {
                                id: 1,
                                name: 'Ada',
                                email: 'ada@example.com',
                            } as never
                        }
                    />
                </DropdownMenu>
            </DropdownMenuTrigger>,
        );

        await user.click(screen.getByRole('button', { name: 'User menu' }));
        await user.click(screen.getByRole('menuitem', { name: 'Log out' }));

        expect(cleanup).toHaveBeenCalled();
        expect(routerFlushAll).toHaveBeenCalled();
        expect(routerPost).toHaveBeenCalledWith('/logout');
    });
});
