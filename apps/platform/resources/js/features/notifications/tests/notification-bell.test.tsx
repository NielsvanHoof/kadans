import { User } from '@react-aria/test-utils';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { WorkspaceNotificationsSummary } from '@/features/notifications/types';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

const routerPost = vi.fn();
let pageProps: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    router: { post: routerPost },
    usePage: () => mockInertiaPage(pageProps),
    Form: ({
        children,
        action,
        onSuccess,
        ...props
    }: {
        children: (state: { processing: boolean }) => React.ReactNode;
        action?: string;
        onSuccess?: () => void;
        method?: string;
        className?: string;
    }) => {
        // Strip Inertia-only props before rendering a DOM form.
        void props;

        return (
            <form
                action={action}
                onSubmit={(event) => {
                    event.preventDefault();
                    onSuccess?.();
                }}
            >
                {children({ processing: false })}
            </form>
        );
    },
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/routes/workspaces/notifications', () => ({
    readAll: {
        url: (tenant: { slug: string }) =>
            `/workspaces/${tenant.slug}/notifications/read-all`,
    },
    clearAll: {
        form: (tenant: { slug: string }) => ({
            action: `/workspaces/${tenant.slug}/notifications/clear-all`,
            method: 'delete' as const,
        }),
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
                name: /Notifications, \d+ unread/,
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('renders the unread count as a corner pip on the bell', () => {
        pageProps.notifications = summary({ unread_count: 1 });

        render(<NotificationBell />);

        const pip = screen.getByText('1');

        expect(pip.tagName).toBe('SPAN');
        expect(pip.className).toContain('absolute');
        expect(pip.className).toContain('rounded-full');
        expect(pip.className).toContain('bg-destructive');
        expect(pip.className).not.toContain('bg-destructive/10');
    });

    it('caps the displayed badge count at 99+', () => {
        pageProps.notifications = summary({ unread_count: 150 });

        render(<NotificationBell />);

        expect(screen.getByText('99+')).toBeInTheDocument();
    });

    it('disables mark-all when there is nothing unread', async () => {
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
                name: /Notifications, \d+ unread/,
            }),
            overlayType: 'popover',
        });

        await dialog.open();

        const markAllRead = within(dialogRoot(dialog)).getByRole('button', {
            name: 'Mark all read',
        });
        await user.click(markAllRead);

        expect(routerPost).toHaveBeenCalledWith(
            '/workspaces/acme/notifications/read-all',
            {},
            { preserveScroll: true, only: ['notifications'] },
        );
    });

    it('disables clear all when the inbox is empty', async () => {
        pageProps.notifications = summary({ unread_count: 0, items: [] });

        render(<NotificationBell />);

        const dialog = testUtilUser.createTester('Dialog', {
            root: screen.getByRole('button', { name: 'Notifications' }),
            overlayType: 'popover',
        });

        await dialog.open();

        const clearAll = within(dialogRoot(dialog)).getByRole('button', {
            name: 'Clear all',
        });

        expect(clearAll).toHaveAttribute('data-disabled', 'true');
    });

    it('opens a confirm dialog before clearing all notifications', async () => {
        const user = userEvent.setup();
        pageProps.notifications = summary({
            unread_count: 1,
            items: [
                {
                    id: 'notif-1',
                    type: 'client_questionnaire_completed',
                    message: 'Done',
                    url: '/workspaces/acme/dossiers/1',
                    read_at: null,
                    created_at: '2026-01-01T10:00:00Z',
                    dossier_id: 1,
                    dossier_title: 'Dossier 1',
                    client_name: null,
                    audio_recording_id: null,
                    original_filename: null,
                },
            ],
        });

        render(<NotificationBell />);

        const dialog = testUtilUser.createTester('Dialog', {
            root: screen.getByRole('button', {
                name: /Notifications, \d+ unread/,
            }),
            overlayType: 'popover',
        });

        await dialog.open();

        await user.click(
            within(dialogRoot(dialog)).getByRole('button', {
                name: 'Clear all',
            }),
        );

        const alert = await screen.findByRole('alertdialog', {
            name: 'Clear all notifications?',
        });

        expect(
            screen.getByText(
                'This removes every notification for this workspace from your inbox. You cannot undo this.',
            ),
        ).toBeInTheDocument();
        expect(alert.querySelector('form')).toHaveAttribute(
            'action',
            '/workspaces/acme/notifications/clear-all',
        );

        await user.click(
            within(alert).getByRole('button', { name: 'Clear all' }),
        );

        expect(
            screen.queryByRole('alertdialog', {
                name: 'Clear all notifications?',
            }),
        ).not.toBeInTheDocument();
    });
});
