import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useRef } from 'react';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';

/**
 * Payload for workspace `.pipeline.updated` (see PipelineUpdated::broadcastWith).
 */
export type PipelineUpdatedPayload = {
    pipeline_id: number;
    type: string;
    status: string;
    subject_type: string;
    subject_id: number;
    dossier_id: number | null;
};

/**
 * Partial-reload Inertia props when a workspace pipeline advances.
 * Replaces usePoll for OCR / export / pipeline live pages.
 */
export function useReloadOnPipelineUpdated(
    only: string[],
    options: {
        enabled?: boolean;
        match?: (event: PipelineUpdatedPayload) => boolean;
    } = {},
): void {
    const workspace = useCurrentWorkspace();
    const enabled = options.enabled ?? true;

    // Keep latest only/match without resubscribing Echo on every render.
    const onlyRef = useRef(only);
    onlyRef.current = only;
    const matchRef = useRef(options.match);
    matchRef.current = options.match;

    const onUpdated = useCallback(
        (event: PipelineUpdatedPayload) => {
            if (!enabled) {
                return;
            }

            if (matchRef.current && !matchRef.current(event)) {
                return;
            }

            router.reload({ only: onlyRef.current });
        },
        [enabled],
    );

    // Leading dot skips Echo's App.Events namespace — matches broadcastAs().
    useEcho(`workspaces.${workspace.slug}`, '.pipeline.updated', onUpdated, [
        workspace.slug,
        onUpdated,
    ]);
}
