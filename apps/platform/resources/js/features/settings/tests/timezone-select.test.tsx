import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const routerPatch = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: routerPatch,
    },
    usePage: () =>
        mockInertiaPage({
            translations: {},
            locale: 'en',
            timezone: 'Europe/Amsterdam',
            available_timezones: [
                { value: 'UTC', label: 'UTC (GMT+00:00)' },
                { value: 'Europe/Amsterdam', label: 'Amsterdam (GMT+02:00)' },
                { value: 'Asia/Tokyo', label: 'Tokyo (GMT+09:00)' },
            ],
        }),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/actions/App/Accounts/Http/Controllers/TimezoneController', () => ({
    default: {
        update: {
            url: () => '/settings/timezone',
        },
    },
}));

const { default: TimezoneSelect } = await import(
    '@/features/settings/timezone-select'
);

describe('TimezoneSelect', () => {
    beforeEach(() => {
        routerPatch.mockReset();
    });

    it('shows the resolved zone as the current selection', () => {
        render(<TimezoneSelect />);

        expect(screen.getByRole('button')).toHaveTextContent(
            'Amsterdam (GMT+02:00)',
        );
    });

    it('persists a new selection to the server', async () => {
        const user = userEvent.setup();

        render(<TimezoneSelect />);

        await user.click(screen.getByRole('button'));
        await user.click(screen.getByRole('option', { name: /Tokyo/ }));

        expect(routerPatch).toHaveBeenCalledWith(
            '/settings/timezone',
            { timezone: 'Asia/Tokyo' },
            { preserveScroll: true },
        );
        // Written client-side too, so the detector and the stored choice agree.
        expect(document.cookie).toContain('timezone=Asia/Tokyo');
    });

    it('does not post when the active zone is reselected', async () => {
        const user = userEvent.setup();

        render(<TimezoneSelect />);

        await user.click(screen.getByRole('button'));
        await user.click(screen.getByRole('option', { name: /Amsterdam/ }));

        expect(routerPatch).not.toHaveBeenCalled();
    });
});
