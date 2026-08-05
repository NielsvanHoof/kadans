import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mockInertiaPage } from '@/test/mocks';

const useAppearance = vi.fn();

vi.mock('@inertiajs/react', () => ({
    usePage: () =>
        mockInertiaPage({
            translations: {},
            locale: 'en',
            available_locales: [],
        }),
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => useAppearance(),
}));

const { default: AppearanceTabs } =
    await import('@/features/settings/appearance-tabs');

describe('AppearanceTabs', () => {
    beforeEach(() => {
        useAppearance.mockReset();
    });

    it('marks the active appearance segment as selected', () => {
        const updateAppearance = vi.fn();
        useAppearance.mockReturnValue({
            appearance: 'dark',
            resolvedAppearance: 'dark',
            updateAppearance,
        });

        render(<AppearanceTabs />);

        expect(screen.getByRole('radio', { name: 'Dark' })).toHaveAttribute(
            'data-selected',
            'true',
        );
        expect(
            screen.getByRole('radio', { name: 'Light' }),
        ).not.toHaveAttribute('data-selected', 'true');
    });

    it('calls updateAppearance with the newly selected segment', async () => {
        const user = userEvent.setup();
        const updateAppearance = vi.fn();
        useAppearance.mockReturnValue({
            appearance: 'light',
            resolvedAppearance: 'light',
            updateAppearance,
        });

        render(<AppearanceTabs />);

        await user.click(screen.getByRole('radio', { name: 'System' }));

        expect(updateAppearance).toHaveBeenCalledWith('system');
    });
});
