import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { WorkspaceNotification } from '@/features/notifications/types';
import type { Firm } from '@/features/workspaces/types';
import { mockUseTranslation } from '@/test/mocks';

const routerPost = vi.fn();
const routerVisit = vi.fn();
const routerDelete = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { post: routerPost, visit: routerVisit, delete: routerDelete },
    Link: ({
        href,
        children,
        onClick,
        className,
    }: {
        href: string;
        children: React.ReactNode;
        onClick?: (event: React.MouseEvent<HTMLAnchorElement>) => void;
        className?: string;
    }) => (
        <a href={href} className={className} onClick={onClick}>
            {children}
        </a>
    ),
}));

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@/routes/workspaces/notifications', () => ({
    read: {
        url: ({
            tenant,
            notification,
        }: {
            tenant: { slug: string };
            notification: string;
        }) => `/workspaces/${tenant.slug}/notifications/${notification}/read`,
    },
    destroy: {
        url: ({
            tenant,
            notification,
        }: {
            tenant: { slug: string };
            notification: string;
        }) => `/workspaces/${tenant.slug}/notifications/${notification}`,
    },
}));

const { default: NotificationListItem } =
    await import('@/features/notifications/notification-list-item');

const workspace: Firm = { slug: 'acme', name: 'Acme' };

function notification(
    overrides: Partial<WorkspaceNotification> = {},
): WorkspaceNotification {
    return {
        id: 'notif-1',
        type: 'client_questionnaire_completed',
        message: 'A reminder was sent',
        url: '/workspaces/acme/dossiers/1',
        read_at: null,
        created_at: '2026-01-01T10:00:00Z',
        dossier_id: 1,
        dossier_title: 'Dossier 1',
        client_name: null,
        audio_recording_id: null,
        original_filename: null,
        ...overrides,
    };
}

beforeEach(() => {
    routerPost.mockReset();
    routerVisit.mockReset();
    routerDelete.mockReset();
});

describe('NotificationListItem', () => {
    it('marks an unread notification read, then navigates on success', async () => {
        const user = userEvent.setup();
        routerPost.mockImplementation((_url, _data, options) => {
            options.onSuccess();
        });

        render(
            <NotificationListItem
                notification={notification({ read_at: null })}
                workspace={workspace}
            />,
        );

        const link = screen.getByRole('link', { name: /A reminder was sent/ });
        expect(link).toHaveAttribute('href', '/workspaces/acme/dossiers/1');

        await user.click(link);

        expect(routerPost).toHaveBeenCalledWith(
            '/workspaces/acme/notifications/notif-1/read',
            {},
            expect.objectContaining({
                preserveScroll: true,
                only: ['notifications'],
            }),
        );
        expect(routerVisit).toHaveBeenCalledWith('/workspaces/acme/dossiers/1');
    });

    it('does not navigate before the mark-read request resolves', async () => {
        const user = userEvent.setup();
        routerPost.mockImplementation(() => {
            // onSuccess deliberately not invoked yet.
        });

        render(
            <NotificationListItem
                notification={notification({ read_at: null })}
                workspace={workspace}
            />,
        );

        await user.click(
            screen.getByRole('link', { name: /A reminder was sent/ }),
        );

        expect(routerPost).toHaveBeenCalled();
        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('navigates directly for an already-read notification, without posting', async () => {
        const user = userEvent.setup();

        render(
            <NotificationListItem
                notification={notification({
                    read_at: '2026-01-01T11:00:00Z',
                })}
                workspace={workspace}
            />,
        );

        await user.click(
            screen.getByRole('link', { name: /A reminder was sent/ }),
        );

        expect(routerPost).not.toHaveBeenCalled();
        // Read items let the Link handle navigation — no router.visit hop.
        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('deletes a notification without navigating', async () => {
        const user = userEvent.setup();

        render(
            <NotificationListItem
                notification={notification()}
                workspace={workspace}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Delete notification' }),
        );

        expect(routerDelete).toHaveBeenCalledWith(
            '/workspaces/acme/notifications/notif-1',
            {
                preserveScroll: true,
                only: ['notifications'],
            },
        );
        expect(routerVisit).not.toHaveBeenCalled();
        expect(routerPost).not.toHaveBeenCalled();
    });
});
