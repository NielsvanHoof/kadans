import { useHttp } from '@inertiajs/react';
import { useCallback } from 'react';
import DocumentRequestController from '@/actions/App/Dossiers/Http/Controllers/DocumentRequestController';
import type {
    DocumentRequest,
    QuestionnaireItemConfiguration,
    QuestionnaireItemType,
} from '@/features/document-requests/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';

export type BuilderSaveStatus = 'idle' | 'saving' | 'saved' | 'error';

type DocumentRequestResponse = {
    document_request: DocumentRequest;
};

/**
 * Builder-island mutations via useHttp (JSON). Local state owns the canvas;
 * the server returns only the touched item (or 204 for reorder/delete).
 */
export function useQuestionnaireBuilderActions(dossierId: number) {
    const currentWorkspace = useCurrentWorkspace();
    const { post: createPost, setData: setCreateData } = useHttp({
        type: 'text' as QuestionnaireItemType,
        title: '',
        position: undefined as number | undefined,
    });
    const { put: updatePut, setData: setUpdateData } = useHttp({
        type: 'text' as QuestionnaireItemType,
        title: '',
        instructions: null as string | null,
        configuration: undefined as QuestionnaireItemConfiguration | undefined,
    });
    const { post: reorderPost, setData: setReorderData } = useHttp({
        document_request_ids: [] as number[],
    });
    const { delete: destroyDelete } = useHttp({});

    const createComponent = useCallback(
        async (input: {
            type: QuestionnaireItemType;
            title: string;
            position?: number;
        }): Promise<DocumentRequest | null> => {
            setCreateData({
                type: input.type,
                title: input.title,
                position: input.position,
            });

            try {
                const response = (await createPost(
                    DocumentRequestController.store.url({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                    }),
                )) as DocumentRequestResponse | undefined;

                return response?.document_request ?? null;
            } catch {
                return null;
            }
        },
        [createPost, currentWorkspace, dossierId, setCreateData],
    );

    const reorderComponents = useCallback(
        async (orderedIds: number[]): Promise<boolean> => {
            setReorderData({ document_request_ids: orderedIds });

            let failed = false;

            try {
                await reorderPost(
                    DocumentRequestController.reorder.url({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                    }),
                    {
                        onError: () => {
                            failed = true;
                        },
                    },
                );

                return !failed;
            } catch {
                return false;
            }
        },
        [currentWorkspace, dossierId, reorderPost, setReorderData],
    );

    const updateComponent = useCallback(
        async (
            documentRequestId: number,
            input: {
                type: QuestionnaireItemType;
                title: string;
                instructions: string | null;
                configuration?: QuestionnaireItemConfiguration;
            },
        ): Promise<DocumentRequest | null> => {
            setUpdateData({
                type: input.type,
                title: input.title,
                instructions: input.instructions,
                configuration: input.configuration,
            });

            try {
                const response = (await updatePut(
                    DocumentRequestController.update.url({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                        documentRequest: documentRequestId,
                    }),
                )) as DocumentRequestResponse | undefined;

                return response?.document_request ?? null;
            } catch {
                return null;
            }
        },
        [currentWorkspace, dossierId, setUpdateData, updatePut],
    );

    const destroyComponent = useCallback(
        async (documentRequestId: number): Promise<boolean> => {
            try {
                await destroyDelete(
                    DocumentRequestController.destroy.url({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                        documentRequest: documentRequestId,
                    }),
                );

                return true;
            } catch {
                return false;
            }
        },
        [currentWorkspace, destroyDelete, dossierId],
    );

    return {
        createComponent,
        reorderComponents,
        updateComponent,
        destroyComponent,
    };
}
