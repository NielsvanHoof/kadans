import { renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PipelineUpdatedPayload } from '@/features/pipelines/types';

/** Captured Echo handler, so tests can emit broadcasts directly. */
let emit: (event: PipelineUpdatedPayload) => void = () => {};
const httpGet = vi.fn();

vi.mock('@laravel/echo-react', () => ({
    // lib/echo.ts calls this on import; no-op under tests.
    configureEcho: vi.fn(),
    useEcho: (
        _channel: string,
        _event: string,
        handler: (event: PipelineUpdatedPayload) => void,
    ) => {
        emit = handler;
    },
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ get: httpGet }),
}));

const { useFetchOnPipelineUpdated } =
    await import('@/features/pipelines/hooks/use-fetch-on-pipeline-updated');

function payload(
    overrides: Partial<PipelineUpdatedPayload> = {},
): PipelineUpdatedPayload {
    return {
        pipeline_id: 1,
        type: 'ocr',
        status: 'processing',
        subject_type: 'uploaded_document',
        subject_id: 7,
        dossier_id: 3,
        ...overrides,
    };
}

beforeEach(() => {
    httpGet.mockReset();
});

describe('useFetchOnPipelineUpdated', () => {
    it('fetches the event URL and hands the result to onData', async () => {
        httpGet.mockResolvedValue({ ok: true });
        const onData = vi.fn();

        renderHook(() =>
            useFetchOnPipelineUpdated({
                scope: { on: 'workspace' },
                urlForEvent: (event) => `/documents/${event.subject_id}/live`,
                onData,
            }),
        );

        emit(payload({ subject_id: 42 }));

        await waitFor(() => expect(onData).toHaveBeenCalledTimes(1));
        expect(httpGet).toHaveBeenCalledWith('/documents/42/live');
        expect(onData.mock.calls[0][0]).toEqual({ ok: true });
    });

    it('ignores events the match predicate rejects', async () => {
        const onData = vi.fn();

        renderHook(() =>
            useFetchOnPipelineUpdated({
                scope: { on: 'workspace' },
                match: (event) => event.subject_id === 1,
                urlForEvent: () => '/x',
                onData,
            }),
        );

        emit(payload({ subject_id: 999 }));

        await waitFor(() => expect(httpGet).not.toHaveBeenCalled());
        expect(onData).not.toHaveBeenCalled();
    });

    it('does nothing while disabled', async () => {
        const onData = vi.fn();

        renderHook(() =>
            useFetchOnPipelineUpdated({
                scope: { on: 'workspace' },
                enabled: false,
                urlForEvent: () => '/x',
                onData,
            }),
        );

        emit(payload());

        await waitFor(() => expect(httpGet).not.toHaveBeenCalled());
        expect(onData).not.toHaveBeenCalled();
    });

    /**
     * Two ticks can overlap; if the first response lands last it would otherwise
     * overwrite newer state. Only the newest generation may apply.
     */
    it('drops a slow response that is overtaken by a newer one', async () => {
        const onData = vi.fn();
        let resolveFirst: (value: unknown) => void = () => {};

        httpGet
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveFirst = resolve;
                    }),
            )
            .mockResolvedValueOnce({ tick: 'second' });

        renderHook(() =>
            useFetchOnPipelineUpdated({
                scope: { on: 'workspace' },
                urlForEvent: () => '/x',
                onData,
            }),
        );

        emit(payload({ pipeline_id: 1 })); // slow
        emit(payload({ pipeline_id: 2 })); // fast, resolves first

        await waitFor(() => expect(onData).toHaveBeenCalledTimes(1));
        expect(onData.mock.calls[0][0]).toEqual({ tick: 'second' });

        resolveFirst({ tick: 'first' });

        // The stale first response must never reach onData.
        await new Promise((resolve) => setTimeout(resolve, 10));
        expect(onData).toHaveBeenCalledTimes(1);
        expect(onData.mock.calls[0][0]).toEqual({ tick: 'second' });
    });

    it('swallows fetch errors so the island keeps its last good state', async () => {
        httpGet.mockRejectedValue(new Error('network'));
        const onData = vi.fn();

        renderHook(() =>
            useFetchOnPipelineUpdated({
                scope: { on: 'workspace' },
                urlForEvent: () => '/x',
                onData,
            }),
        );

        expect(() => emit(payload())).not.toThrow();

        await new Promise((resolve) => setTimeout(resolve, 10));
        expect(onData).not.toHaveBeenCalled();
    });

    it('ignores an undefined response body', async () => {
        httpGet.mockResolvedValue(undefined);
        const onData = vi.fn();

        renderHook(() =>
            useFetchOnPipelineUpdated({
                scope: { on: 'workspace' },
                urlForEvent: () => '/x',
                onData,
            }),
        );

        emit(payload());

        await new Promise((resolve) => setTimeout(resolve, 10));
        expect(onData).not.toHaveBeenCalled();
    });
});
