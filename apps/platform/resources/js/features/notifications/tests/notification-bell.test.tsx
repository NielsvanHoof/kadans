import { User } from '@react-aria/test-utils';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { WorkspaceNotificationsSummary } from '@/features/notifications/types';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const routerPost = vi.fn();
let pageProps: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    router: { post: routerPost },
    usePage: () => mockInertiaPage(pageProps),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/routes/workspaces/notifications', () => ({
    readAll: {
        url: (tenant: { slug: string }) =>
            `/workspaces/${tenant.slug}/notifications/read-all`,
    },
}));

vi.mock('@/features/notifications/notification-list', () => ({
    default: () => <div>notification list</div>,
}));

const { default: NotificationBell } =
    await import('@/features/notifications/notification-bell');

const testUtilUser = new User({
    interactionType: 'mouse',
    advanceTimer: async (ms) => {
        await new Promise((resolve) => setTimeout(resolve, ms));
    },
});

function dialogRoot(dialog: { getDialog(): HTMLElement | null }): HTMLElement {
    const element = dialog.getDialog();
    if (!element) {
        throw new Error('Expected dialog to be open');
    }

    return element;
}

function summary(
    overrides: Partial<WorkspaceNotificationsSummary> = {},
): WorkspaceNotificationsSummary {
    return {
        unread_count: 0,
        items: [],
        ...overrides,
    };
}

beforeEach(() => {
    routerPost.mockReset();
    pageProps = {
        current_firm: { slug: 'acme', name: 'Acme' },
        notifications: summary(),
    };
});

describe('NotificationBell', () => {
    it('renders nothing outside a workspace', () => {
        pageProps = { current_firm: null, notifications: summary() };

        const { container } = render(<NotificationBell />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when the notifications prop is missing', () => {
        pageProps = { current_firm: { slug: 'acme', name: 'Acme' } };

        const { container } = render(<NotificationBell />);

        expect(container).toBeEmptyDOMElement();
    });

    it('hides the unread badge when there are no unread notifications', () => {
        pageProps.notifications = summary({ unread_count: 0 });

        render(<NotificationBell />);

        expect(
            screen.getByRole('button', { name: 'Notifications' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('0')).not.toBeInTheDocument();
    });

    it('shows the unread count on the badge and in the label', () => {
        pageProps.notifications = summary({ unread_count: 3 });

        render(<NotificationBell />);

        expect(
            screen.getByRole('button', {
                name: 'Notifications, :count unread',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('caps the displayed badge count at 99+', () => {
        pageProps.notifications = summary({ unread_count: 150 });

        render(<NotificationBell />);

        expect(screen.getByText('99+')).toBeInTheDocument();
    });

    it('posts to read-all and does not throw when there is nothing unread', async () => {
        pageProps.notifications = summary({ unread_count: 0 });

        render(<NotificationBell />);

        const dialog = testUtilUser.createTester('Dialog', {
            root: screen.getByRole('button', { name: 'Notifications' }),
            overlayType: 'popover',
        });

        await dialog.open();

        const markAllRead = within(dialogRoot(dialog)).getByRole('button', {
            name: 'Mark all read',
        });

        expect(markAllRead).toHaveAttribute('data-disabled', 'true');
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('marks all read via a partial reload', async () => {
        const user = userEvent.setup();
        pageProps.notifications = summary({ unread_count: 2 });

        render(<NotificationBell />);

        const dialog = testUtilUser.createTester('Dialog', {
            root: screen.getByRole('button', {
                name: 'Notifications, :count unread',
            }),
            overlayType: 'popover',
        });

        await dialog.open();

        const markAllRead = within(dialogRoot(dialog)).getByRole('button', {
            name: 'Mark all read',
        });
        // userEvent wraps the press in act() so RAC Button state updates
        // do not warn about updates outside of a test act boundary.
        await user.click(markAllRead);

        expect(routerPost).toHaveBeenCalledWith(
            '/workspaces/acme/notifications/read-all',
            {},
            { preserveScroll: true, only: ['notifications'] },
        );
    });
});
