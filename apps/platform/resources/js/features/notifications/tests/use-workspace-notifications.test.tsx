import { render, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PipelineUpdatedPayload } from '@/features/pipelines/types';
import { mockInertiaPage } from '@/test/mocks';

/**
 * Captured Echo handlers, keyed by event name, so tests can emit broadcasts
 * directly. useWorkspaceNotifications subscribes twice: once itself
 * (questionnaire.completed) and once via usePipelineUpdatedSubscription
 * (pipeline.updated, filtered to completed audio transcriptions).
 */
const echoHandlers: Record<string, (event: unknown) => void> = {};
const routerReload = vi.fn();
let pageProps: Record<string, unknown> = {};

vi.mock('@laravel/echo-react', () => ({
    // lib/echo.ts calls this on import; no-op under tests.
    configureEcho: vi.fn(),
    useEcho: (
        _channel: string,
        event: string,
        handler: (event: unknown) => void,
    ) => {
        echoHandlers[event] = handler;
    },
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: routerReload },
    usePage: () => mockInertiaPage(pageProps),
}));

const { useWorkspaceNotifications, WorkspaceNotifications } =
    await import('@/features/notifications/use-workspace-notifications');

function pipelinePayload(
    overrides: Partial<PipelineUpdatedPayload> = {},
): PipelineUpdatedPayload {
    return {
        pipeline_id: 1,
        type: 'audio_transcription',
        status: 'completed',
        subject_type: 'audio_recording',
        subject_id: 1,
        dossier_id: 1,
        ...overrides,
    };
}

beforeEach(() => {
    routerReload.mockReset();
    for (const key of Object.keys(echoHandlers)) {
        delete echoHandlers[key];
    }
});

describe('useWorkspaceNotifications', () => {
    it('reloads the notifications prop on a questionnaire.completed broadcast', () => {
        renderHook(() => useWorkspaceNotifications('acme'));

        echoHandlers['.client.questionnaire.completed']({});

        expect(routerReload).toHaveBeenCalledWith({ only: ['notifications'] });
    });

    it('reloads on a completed audio transcription pipeline event', () => {
        renderHook(() => useWorkspaceNotifications('acme'));

        echoHandlers['.pipeline.updated'](
            pipelinePayload({
                type: 'audio_transcription',
                status: 'completed',
            }),
        );

        expect(routerReload).toHaveBeenCalledTimes(1);
    });

    it('ignores pipeline events that are not a completed audio transcription', () => {
        renderHook(() => useWorkspaceNotifications('acme'));

        echoHandlers['.pipeline.updated'](
            pipelinePayload({ type: 'ocr', status: 'completed' }),
        );
        echoHandlers['.pipeline.updated'](
            pipelinePayload({
                type: 'audio_transcription',
                status: 'processing',
            }),
        );

        expect(routerReload).not.toHaveBeenCalled();
    });
});

describe('WorkspaceNotifications', () => {
    it('renders nothing when there is no authenticated user', () => {
        pageProps = { auth: { user: null }, current_firm: { slug: 'acme' } };

        const { container } = render(<WorkspaceNotifications />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing outside a workspace', () => {
        pageProps = { auth: { user: { id: 1 } }, current_firm: null };

        const { container } = render(<WorkspaceNotifications />);

        expect(container).toBeEmptyDOMElement();
    });

    it('mounts the Echo subscription inside a workspace', () => {
        pageProps = {
            auth: { user: { id: 1 } },
            current_firm: { slug: 'acme' },
        };

        render(<WorkspaceNotifications />);

        expect(echoHandlers['.client.questionnaire.completed']).toBeDefined();
    });
});
