import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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

const { default: LocaleTabs } = await import('@/features/settings/locale-tabs');

describe('LocaleTabs', () => {
    beforeEach(() => {
        routerPatch.mockReset();
        localStorage.clear();
    });

    it('marks the active locale segment as selected', () => {
        render(<LocaleTabs />);

        expect(screen.getByRole('radio', { name: 'English' })).toHaveAttribute(
            'data-selected',
            'true',
        );
        expect(
            screen.getByRole('radio', { name: 'Dutch' }),
        ).not.toHaveAttribute('data-selected', 'true');
    });

    it('persists the new locale to localStorage, cookie, and server on change', async () => {
        const user = userEvent.setup();

        render(<LocaleTabs />);

        await user.click(screen.getByRole('radio', { name: 'Dutch' }));

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

        render(<LocaleTabs />);

        await user.click(screen.getByRole('radio', { name: 'English' }));

        expect(routerPatch).not.toHaveBeenCalled();
    });
});
