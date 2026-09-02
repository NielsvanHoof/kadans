import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback } from 'react';
import '@/lib/echo';

/**
 * Subscribe to workspace questionnaire-complete broadcasts.
 * Reloads the shared notifications prop so the bell updates without a toast.
 */
export function useWorkspaceNotifications(tenantSlug: string): void {
    const reloadNotifications = useCallback(() => {
        // Partial reload refreshes the bell badge/list without a full page visit.
        router.reload({
            only: ['notifications'],
        });
    }, []);

    // Transcription used to filter the whole pipeline.updated stream. Because
    // this hook mounts in AppLayout that made every staff member a subscriber
    // to every run in the firm, on every page — the reason that stream could
    // not be narrowed. Both events share this channel and the same reload.
    useEcho(
        `workspaces.${tenantSlug}`,
        ['.client.questionnaire.completed', '.audio.transcription.completed'],
        reloadNotifications,
        [tenantSlug],
    );
}

/**
 * Mounts the workspace Echo subscription when staff are inside a firm.
 * Safe to render from AppLayout (no-ops outside a workspace).
 */
export function WorkspaceNotifications(): React.ReactElement | null {
    const { current_firm: currentFirm, auth } = usePage().props;

    if (!auth.user || !currentFirm?.slug) {
        return null;
    }

    return <WorkspaceNotificationsSubscriber slug={currentFirm.slug} />;
}

function WorkspaceNotificationsSubscriber({ slug }: { slug: string }): null {
    useWorkspaceNotifications(slug);

    return null;
}
