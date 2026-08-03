import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback } from 'react';
import { usePipelineUpdatedSubscription } from '@/features/pipelines/hooks/use-pipeline-updated-subscription';

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

    // Leading dot skips Echo's App.Events namespace — matches broadcastAs().
    useEcho(
        `workspaces.${tenantSlug}`,
        '.client.questionnaire.completed',
        reloadNotifications,
        [tenantSlug],
    );

    // Audio transcription has no dedicated broadcast event — it reuses the
    // existing pipeline.updated stream every other pipeline feature listens to.
    usePipelineUpdatedSubscription(reloadNotifications, {
        match: (event) =>
            event.type === 'audio_transcription' &&
            event.status === 'completed',
    });
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
