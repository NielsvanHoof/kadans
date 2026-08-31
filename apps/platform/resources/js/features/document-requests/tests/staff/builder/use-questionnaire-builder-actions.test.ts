import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { DocumentRequest } from '@/features/document-requests/types';

const syncPut = vi.fn();
const setSyncData = vi.fn();
const routerFlash = vi.fn();

// useHttp + flash are the only collaborators under test; Wayfinder URL builders
// are mocked so we do not depend on generated action output in unit tests.
vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ put: syncPut, setData: setSyncData }),
    router: { flash: routerFlash },
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock(
    '@/actions/App/Dossiers/Http/Controllers/DocumentRequestController',
    () => ({
        default: {
            sync: {
                url: () => '/workspaces/acme/dossiers/7/document-requests/sync',
            },
        },
    }),
);

const { useQuestionnaireBuilderActions } =
    await import('@/features/document-requests/staff/builder/use-questionnaire-builder-actions');

function item(
    overrides: Partial<DocumentRequest> & Pick<DocumentRequest, 'id'>,
): DocumentRequest {
    return {
        type: 'text',
        field_key: `key-${overrides.id}`,
        title: `Item ${overrides.id}`,
        instructions: null,
        configuration: { is_required: true },
        status: 'pending',
        answer_text: null,
        answer_boolean: null,
        answer_option_ids: null,
        answered_at: null,
        reviewed_at: null,
        reviewed_by_name: null,
        rejection_reason: null,
        sort_order: 0,
        is_visible: true,
        is_applicable: true,
        uploaded_documents: [],
        ...overrides,
    } as DocumentRequest;
}

beforeEach(() => {
    syncPut.mockReset();
    setSyncData.mockReset();
    routerFlash.mockReset();
});

describe('useQuestionnaireBuilderActions', () => {
    it('maps positive ids through and nulls out draft (negative) ids', async () => {
        syncPut.mockResolvedValue({
            document_requests: [item({ id: 10 })],
        });
        const { result } = renderHook(() => useQuestionnaireBuilderActions(7));

        await act(async () => {
            await result.current.syncDocumentRequests([
                item({ id: 10, title: 'Saved' }),
                item({ id: -1, title: 'Draft', field_key: 'draft' }),
            ]);
        });

        expect(setSyncData).toHaveBeenCalledWith({
            items: [
                {
                    id: 10,
                    field_key: 'key-10',
                    type: 'text',
                    title: 'Saved',
                    instructions: null,
                    configuration: { is_required: true },
                },
                {
                    id: null,
                    field_key: 'draft',
                    type: 'text',
                    title: 'Draft',
                    instructions: null,
                    configuration: { is_required: true },
                },
            ],
        });
        expect(syncPut).toHaveBeenCalledWith(
            '/workspaces/acme/dossiers/7/document-requests/sync',
        );
    });

    it('returns the synced document_requests on success', async () => {
        const synced = [item({ id: 10, title: 'From server' })];
        syncPut.mockResolvedValue({ document_requests: synced });
        const { result } = renderHook(() => useQuestionnaireBuilderActions(7));

        let returned: DocumentRequest[] | null = null;
        await act(async () => {
            returned = await result.current.syncDocumentRequests([
                item({ id: 10 }),
            ]);
        });

        expect(returned).toEqual(synced);
        expect(routerFlash).not.toHaveBeenCalled();
    });

    it('re-applies flash toast when the sync response includes one', async () => {
        const toast = {
            type: 'success' as const,
            message: 'Saved',
        };
        syncPut.mockResolvedValue({
            document_requests: [item({ id: 10 })],
            flash: { toast },
        });
        const { result } = renderHook(() => useQuestionnaireBuilderActions(7));

        await act(async () => {
            await result.current.syncDocumentRequests([item({ id: 10 })]);
        });

        expect(routerFlash).toHaveBeenCalledWith({ toast });
    });

    it('returns null when the HTTP call fails', async () => {
        syncPut.mockRejectedValue(new Error('network'));
        const { result } = renderHook(() => useQuestionnaireBuilderActions(7));

        let returned: DocumentRequest[] | null = item({ id: 1 }) as never;
        await act(async () => {
            returned = await result.current.syncDocumentRequests([
                item({ id: 10 }),
            ]);
        });

        expect(returned).toBeNull();
        expect(routerFlash).not.toHaveBeenCalled();
    });

    it('returns null when the response omits document_requests', async () => {
        syncPut.mockResolvedValue({});
        const { result } = renderHook(() => useQuestionnaireBuilderActions(7));

        let returned: DocumentRequest[] | null = item({ id: 1 }) as never;
        await act(async () => {
            returned = await result.current.syncDocumentRequests([
                item({ id: 10 }),
            ]);
        });

        expect(returned).toBeNull();
    });
});
