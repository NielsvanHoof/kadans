import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import {
    type PipelineUpdatedSubscription,
    usePipelineUpdatedSubscription,
} from '@/features/pipelines/hooks/use-pipeline-updated-subscription';

/**
 * Partial-reload Inertia props when a workspace pipeline advances.
 * Replaces usePoll for OCR / export / pipeline live pages.
 */
export function useReloadOnPipelineUpdated(
    only: string[],
    options: PipelineUpdatedSubscription = {},
): void {
    const onlyKey = only.join(',');

    const reload = useCallback(() => {
        router.reload({ only: onlyKey.split(',') });
    }, [onlyKey]);

    usePipelineUpdatedSubscription(reload, options);
}
