import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback } from 'react';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import '@/lib/echo';

/**
 * Partial-reload a page when a file's security check finishes.
 *
 * The check is asynchronous and usually quick, which is exactly why this is
 * needed: someone who has just uploaded a document is looking at the page while
 * the verdict is in flight, and without this the file would sit on "running
 * security check" until they reloaded by hand — which is also when the preview
 * would appear, long after it was ready.
 *
 * Same shape as the export and pipeline subscriptions: Echo on
 * workspaces.{slug}, leading-dot event name. Preferred over polling, since the
 * workspace channel is already authorised for staff and most pages will receive
 * no events at all.
 */
export function useReloadOnDocumentScanStateChanged({
    enabled = true,
    only = ['dossier'],
}: {
    /**
     * Skip the reload when nothing on the page is waiting on a check. The
     * channel is the whole firm, so a page with no file in flight would
     * otherwise reload every time any colleague uploaded anything.
     */
    enabled?: boolean;
    only?: string[];
} = {}): void {
    const workspace = useCurrentWorkspace();
    const onlyKey = only.join(',');

    const reload = useCallback(() => {
        if (!enabled) {
            return;
        }

        router.reload({ only: onlyKey.split(',') });
    }, [enabled, onlyKey]);

    useEcho(
        `workspaces.${workspace.slug}`,
        '.document.scan.state_changed',
        reload,
        [workspace.slug, reload],
    );
}
