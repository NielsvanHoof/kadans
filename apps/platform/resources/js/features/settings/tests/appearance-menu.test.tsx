import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage } from '@/test/mocks';

const updateAppearance = vi.fn();
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

// Icons are visually distinct only by SVG contents; stub Sun/Moon/Monitor with
// identifiable test ids so the trigger-icon-follows-resolved-theme logic is
// assertable, while keeping the rest of the module (e.g. CheckIcon used by
// DropdownMenuItem) intact.
vi.mock('lucide-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('lucide-react')>();

    return {
        ...actual,
        Sun: (props: Record<string, unknown>) => (
            <svg data-testid="icon-sun" {...props} />
        ),
        Moon: (props: Record<string, unknown>) => (
            <svg data-testid="icon-moon" {...props} />
        ),
        Monitor: (props: Record<string, unknown>) => (
            <svg data-testid="icon-monitor" {...props} />
        ),
    };
});

const { default: AppearanceMenu } =
    await import('@/features/settings/appearance-menu');

describe('AppearanceMenu', () => {
    beforeEach(() => {
        updateAppearance.mockReset();
        useAppearance.mockReset();
    });

    it('shows the moon trigger icon when the resolved appearance is dark', () => {
        useAppearance.mockReturnValue({
            appearance: 'dark',
            resolvedAppearance: 'dark',
            updateAppearance,
        });

        render(<AppearanceMenu />);

        const trigger = screen.getByRole('button', { name: 'Appearance' });
        expect(
            trigger.querySelector('[data-testid="icon-moon"]'),
        ).toBeInTheDocument();
        expect(
            trigger.querySelector('[data-testid="icon-sun"]'),
        ).not.toBeInTheDocument();
    });

    it('shows the sun trigger icon when the resolved appearance is light', () => {
        useAppearance.mockReturnValue({
            appearance: 'system',
            resolvedAppearance: 'light',
            updateAppearance,
        });

        render(<AppearanceMenu />);

        const trigger = screen.getByRole('button', { name: 'Appearance' });
        expect(
            trigger.querySelector('[data-testid="icon-sun"]'),
        ).toBeInTheDocument();
    });

    it('marks the active appearance as checked in the menu', async () => {
        const user = userEvent.setup();
        useAppearance.mockReturnValue({
            appearance: 'dark',
            resolvedAppearance: 'dark',
            updateAppearance,
        });

        render(<AppearanceMenu />);

        await user.click(screen.getByRole('button', { name: 'Appearance' }));

        expect(
            screen.getByRole('menuitemradio', { name: 'Dark' }),
        ).toHaveAttribute('aria-checked', 'true');
        expect(
            screen.getByRole('menuitemradio', { name: 'Light' }),
        ).toHaveAttribute('aria-checked', 'false');
    });

    it('calls updateAppearance with the newly selected option', async () => {
        const user = userEvent.setup();
        useAppearance.mockReturnValue({
            appearance: 'light',
            resolvedAppearance: 'light',
            updateAppearance,
        });

        render(<AppearanceMenu />);

        await user.click(screen.getByRole('button', { name: 'Appearance' }));
        await user.click(screen.getByRole('menuitemradio', { name: 'System' }));

        expect(updateAppearance).toHaveBeenCalledWith('system');
    });
});
