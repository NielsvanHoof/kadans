import { useHttp } from '@inertiajs/react';
import { useCallback, useRef } from 'react';
import {
    type PipelineUpdatedSubscription,
    usePipelineUpdatedSubscription,
} from '@/features/pipelines/hooks/use-pipeline-updated-subscription';
import type { PipelineUpdatedPayload } from '@/features/pipelines/types';

/**
 * Echo pipeline.updated → fetch a JSON URL → apply the result locally.
 * Prefer this over router.reload when only a narrow slice changed.
 */
export function useFetchOnPipelineUpdated<TResponse>({
    enabled = true,
    match,
    scope,
    urlForEvent,
    onData,
}: PipelineUpdatedSubscription & {
    /** Build the JSON endpoint for this broadcast (subject id / type). */
    urlForEvent: (event: PipelineUpdatedPayload) => string;
    onData: (data: TResponse, event: PipelineUpdatedPayload) => void;
}): void {
    const { get } = useHttp({});

    const urlForEventRef = useRef(urlForEvent);
    urlForEventRef.current = urlForEvent;
    const onDataRef = useRef(onData);
    onDataRef.current = onData;
    const getRef = useRef(get);
    getRef.current = get;
    // Drop stale responses when several pipeline ticks overlap.
    const fetchGenerationRef = useRef(0);

    const fetchAndApply = useCallback(async (event: PipelineUpdatedPayload) => {
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
    }, []);

    usePipelineUpdatedSubscription(fetchAndApply, { enabled, match, scope });
}
