import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage } from '@/test/mocks';

const routerPatch = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: routerPatch,
    },
    usePage: () =>
        mockInertiaPage({
            translations: {},
            locale: 'en',
            available_locales: [
                { code: 'en', label: 'English' },
                { code: 'nl', label: 'Dutch' },
            ],
        }),
}));

vi.mock('@/actions/App/Accounts/Http/Controllers/LocaleController', () => ({
    default: {
        update: {
            url: () => '/locale',
        },
    },
}));

const { default: LocaleMenu } = await import('@/features/settings/locale-menu');

describe('LocaleMenu', () => {
    beforeEach(() => {
        routerPatch.mockReset();
        localStorage.clear();
    });

    it('marks the active locale as checked in the menu', async () => {
        const user = userEvent.setup();

        render(<LocaleMenu />);

        await user.click(screen.getByRole('button', { name: 'Language' }));

        expect(
            screen.getByRole('menuitemradio', { name: 'English' }),
        ).toHaveAttribute('aria-checked', 'true');
        expect(
            screen.getByRole('menuitemradio', { name: 'Dutch' }),
        ).toHaveAttribute('aria-checked', 'false');
    });

    it('persists the new locale to localStorage, cookie, and server on change', async () => {
        const user = userEvent.setup();

        render(<LocaleMenu />);

        await user.click(screen.getByRole('button', { name: 'Language' }));
        await user.click(screen.getByRole('menuitemradio', { name: 'Dutch' }));

        expect(localStorage.getItem('locale')).toBe('nl');
        expect(document.cookie).toContain('locale=nl');
        expect(routerPatch).toHaveBeenCalledWith(
            '/locale',
            { locale: 'nl' },
            { preserveScroll: true },
        );
    });

    it('does not post when re-selecting the already-active locale', async () => {
        const user = userEvent.setup();

        render(<LocaleMenu />);

        await user.click(screen.getByRole('button', { name: 'Language' }));
        await user.click(
            screen.getByRole('menuitemradio', { name: 'English' }),
        );

        expect(routerPatch).not.toHaveBeenCalled();
    });
});
