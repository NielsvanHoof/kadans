import { useHttp } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useRef } from 'react';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import type { PipelineUpdatedPayload } from '@/hooks/use-reload-on-pipeline-updated';

/**
 * Echo pipeline.updated → fetch a JSON URL → apply the result locally.
 * Prefer this over router.reload when only a narrow slice changed.
 */
export function useFetchOnPipelineUpdated<TResponse>({
    enabled = true,
    match,
    urlForEvent,
    onData,
}: {
    enabled?: boolean;
    match?: (event: PipelineUpdatedPayload) => boolean;
    /** Build the JSON endpoint for this broadcast (subject id / type). */
    urlForEvent: (event: PipelineUpdatedPayload) => string;
    onData: (data: TResponse, event: PipelineUpdatedPayload) => void;
}): void {
    const workspace = useCurrentWorkspace();
    const { get } = useHttp({});

    const matchRef = useRef(match);
    matchRef.current = match;
    const urlForEventRef = useRef(urlForEvent);
    urlForEventRef.current = urlForEvent;
    const onDataRef = useRef(onData);
    onDataRef.current = onData;
    const getRef = useRef(get);
    getRef.current = get;
    // Drop stale responses when several pipeline ticks overlap.
    const fetchGenerationRef = useRef(0);

    const onUpdated = useCallback(
        async (event: PipelineUpdatedPayload) => {
            if (!enabled) {
                return;
            }

            if (matchRef.current && !matchRef.current(event)) {
                return;
            }

            const generation = ++fetchGenerationRef.current;

            try {
                const data = (await getRef.current(
                    urlForEventRef.current(event),
                )) as TResponse | undefined;

                if (
                    data === undefined ||
                    generation !== fetchGenerationRef.current
                ) {
                    return;
                }

                onDataRef.current(data, event);
            } catch {
                // Keep previous island state; next Echo tick can retry.
            }
        },
        [enabled],
    );

    useEcho(`workspaces.${workspace.slug}`, '.pipeline.updated', onUpdated, [
        workspace.slug,
        onUpdated,
    ]);
}
