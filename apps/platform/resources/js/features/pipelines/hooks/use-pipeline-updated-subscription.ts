import { useEcho } from '@laravel/echo-react';
import { useCallback, useRef } from 'react';
import type { PipelineUpdatedPayload } from '@/features/pipelines/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import '@/lib/echo';

/**
 * Which pipeline stream a surface attaches to.
 *
 * Required rather than defaulted, because the safe-looking default is the
 * expensive one: a surface that forgets to say would silently join the
 * firm-wide firehose, which is the problem this split exists to fix.
 */
export type PipelineUpdatedScope =
    /** Runs on one dossier — the subscribers are the people looking at it. */
    | { on: 'dossier'; dossierId: number }
    /**
     * Every run in the firm. For the pipeline admin pages, and for subject
     * pages whose dossier is not always known.
     */
    | { on: 'workspace' };

export type PipelineUpdatedSubscription = {
    enabled?: boolean;
    /** Narrow to the pipelines this surface cares about. */
    match?: (event: PipelineUpdatedPayload) => boolean;
    scope: PipelineUpdatedScope;
};

/**
 * Channel names are built here and in PipelineUpdated::broadcastOn(). Keep them
 * in step — Echo fails silently on a channel nobody publishes to.
 */
function channelFor(
    workspaceSlug: string,
    scope: PipelineUpdatedScope,
): string {
    return scope.on === 'dossier'
        ? `workspaces.${workspaceSlug}.dossiers.${scope.dossierId}`
        : `workspaces.${workspaceSlug}.pipelines`;
}

/**
 * Subscribe to the `pipeline.updated` broadcast for one scope.
 *
 * Shared by useReloadOnPipelineUpdated and useFetchOnPipelineUpdated, which
 * differ only in what they do with the event. Keeps the channel name, the
 * leading-dot event name, and the enabled/match plumbing in one place.
 *
 * `match` is still worth setting on a dossier scope: the channel narrows the
 * stream to one dossier, not to one subject or run within it.
 */
export function usePipelineUpdatedSubscription(
    onUpdated: (event: PipelineUpdatedPayload) => void,
    { enabled = true, match, scope }: PipelineUpdatedSubscription,
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

    const channel = channelFor(workspace.slug, scope);

    useEcho(channel, '.pipeline.updated', handler, [channel, handler]);
}
