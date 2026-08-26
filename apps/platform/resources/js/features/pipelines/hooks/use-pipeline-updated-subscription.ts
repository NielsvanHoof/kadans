import { useEcho } from '@laravel/echo-react';
import { useCallback, useRef } from 'react';
import type { PipelineUpdatedPayload } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import '@/lib/echo';

export type PipelineUpdatedSubscription = {
    enabled?: boolean;
    /** Narrow to the pipelines this surface cares about. */
    match?: (event: PipelineUpdatedPayload) => boolean;
};

/**
 * Subscribe to the workspace `pipeline.updated` broadcast.
 *
 * Shared by useReloadOnPipelineUpdated and useFetchOnPipelineUpdated, which
 * differ only in what they do with the event. Keeps the channel name, the
 * leading-dot event name, and the enabled/match plumbing in one place.
 */
export function usePipelineUpdatedSubscription(
    onUpdated: (event: PipelineUpdatedPayload) => void,
    { enabled = true, match }: PipelineUpdatedSubscription = {},
): void {
    const workspace = useCurrentWorkspace();

    // Keep the latest callbacks without resubscribing Echo on every render.
    const onUpdatedRef = useRef(onUpdated);
    onUpdatedRef.current = onUpdated;
    const matchRef = useRef(match);
    matchRef.current = match;

    const handler = useCallback(
        (event: PipelineUpdatedPayload) => {
            if (!enabled) {
                return;
            }

            if (matchRef.current && !matchRef.current(event)) {
                return;
            }

            onUpdatedRef.current(event);
        },
        [enabled],
    );

    useEcho(`workspaces.${workspace.slug}`, '.pipeline.updated', handler, [
        workspace.slug,
        handler,
    ]);
}
