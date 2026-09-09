import { router, useHttp } from '@inertiajs/react';
import { useCallback } from 'react';
import DocumentRequestController from '@/actions/App/Dossiers/Http/Controllers/DocumentRequests/DocumentRequestController';
import type {
    DocumentRequest,
    QuestionnaireItemConfiguration,
    QuestionnaireItemType,
} from '@/features/document-requests/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import type { FlashToast } from '@/types/ui';

export type BuilderSaveStatus = 'idle' | 'saving' | 'saved' | 'error';

export type BuilderSyncItem = {
    /** Positive persisted id, or null for a new draft row. */
    id: number | null;
    field_key: string;
    type: QuestionnaireItemType;
    title: string;
    instructions: string | null;
    configuration: QuestionnaireItemConfiguration | null;
};

type SyncResponse = {
    document_requests: DocumentRequest[];
    flash?: {
        toast?: FlashToast;
    };
};

/**
 * Persist the full builder draft via bulk sync (JSON).
 */
export function useQuestionnaireBuilderActions(dossierId: number) {
    const currentWorkspace = useCurrentWorkspace();
    const { put: syncPut, setData: setSyncData } = useHttp({
        items: [] as BuilderSyncItem[],
    });

    const syncDocumentRequests = useCallback(
        async (items: DocumentRequest[]): Promise<DocumentRequest[] | null> => {
            const payload: BuilderSyncItem[] = items.map((item) => ({
                id: item.id > 0 ? item.id : null,
                field_key: item.field_key,
                type: item.type,
                title: item.title,
                instructions: item.instructions,
                configuration: item.configuration,
            }));

            setSyncData({ items: payload });

            try {
                const response = (await syncPut(
                    DocumentRequestController.sync.url({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                    }),
                )) as SyncResponse | undefined;

                const documentRequests = response?.document_requests ?? null;

                // useHttp is not an Inertia visit, so server Inertia::flash() is not
                // delivered automatically — re-apply the flash payload for toasts.
                if (documentRequests !== null && response?.flash?.toast) {
                    router.flash({ toast: response.flash.toast });
                }

                return documentRequests;
            } catch {
                return null;
            }
        },
        [currentWorkspace, dossierId, setSyncData, syncPut],
    );

    return {
        syncDocumentRequests,
    };
}
