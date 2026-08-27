import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback } from 'react';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import '@/lib/echo';

/**
 * Partial-reload the data-subject request list when a queued export finishes.
 *
 * Matches the pipeline live-page pattern (Echo on workspaces.{slug}, leading-dot
 * event name). Prefer this over usePoll — the build can take minutes and the
 * workspace channel is already authorised for staff.
 */
export function useReloadOnDataSubjectExportCompleted(
    only: string[] = ['requests'],
): void {
    const workspace = useCurrentWorkspace();
    const onlyKey = only.join(',');

    const reload = useCallback(() => {
        router.reload({ only: onlyKey.split(',') });
    }, [onlyKey]);

    useEcho(
        `workspaces.${workspace.slug}`,
        '.data-subject.export.completed',
        reload,
        [workspace.slug, reload],
    );
}
