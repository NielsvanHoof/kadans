import { useEffect, useState, useTransition } from 'react';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import {
    type BuilderSaveStatus,
    useQuestionnaireBuilderActions,
} from '@/features/document-requests/staff/builder/use-questionnaire-builder-actions';
import type {
    DocumentRequest,
    QuestionnaireItemConfiguration,
    QuestionnaireItemType,
} from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Local builder island state: canvas items, selection, and save status.
 * Mutations go through useHttp — no full-dossier Inertia reload.
 */
export function useQuestionnaireBuilderState({
    dossierId,
    documentRequests,
    canEdit,
}: {
    dossierId: number;
    documentRequests: DocumentRequest[];
    canEdit: boolean;
}) {
    const { t } = useTranslation();
    const {
        createComponent,
        reorderComponents,
        updateComponent,
        destroyComponent,
    } = useQuestionnaireBuilderActions(dossierId);

    const [items, setItems] = useState(documentRequests);
    const [selectedId, setSelectedId] = useState<number | null>(
        documentRequests[0]?.id ?? null,
    );
    const [saveStatus, setSaveStatus] = useState<BuilderSaveStatus>('idle');
    const [announcement, setAnnouncement] = useState('');
    const [isInsertPending, startInsertTransition] = useTransition();

    // Sync only when Inertia replaces the initial page props (e.g. apply template).
    useEffect(() => {
        setItems(documentRequests);
        setSelectedId((current) => {
            if (
                current !== null &&
                !documentRequests.some((item) => item.id === current)
            ) {
                return documentRequests[0]?.id ?? null;
            }

            return current;
        });
    }, [documentRequests]);

    const addAt = (type: QuestionnaireItemType, position?: number) => {
        if (!canEdit || isInsertPending) {
            return;
        }

        const definition = getQuestionnaireItemTypeDefinition(type);
        const selectedIndex =
            selectedId === null
                ? -1
                : items.findIndex((item) => item.id === selectedId);
        const insertAt =
            position ?? (selectedIndex >= 0 ? selectedIndex + 1 : items.length);
        const title = t(definition.defaultTitle);

        setSaveStatus('saving');
        setAnnouncement(t('Adding component…'));

        startInsertTransition(async () => {
            const created = await createComponent({
                type,
                title,
                position: insertAt,
            });

            if (!created) {
                setSaveStatus('error');
                setAnnouncement(t('Could not add the component. Try again.'));

                return;
            }

            setItems((previous) => {
                const next = [...previous];
                next.splice(insertAt, 0, created);

                // Keep local sort_order aligned with canvas order after a mid-list insert.
                return next.map((item, index) => ({
                    ...item,
                    sort_order: index,
                }));
            });
            setSelectedId(created.id);
            setSaveStatus('saved');
            setAnnouncement(t('Component added.'));
        });
    };

    const handleReorder = async (orderedIds: number[]): Promise<boolean> => {
        setSaveStatus('saving');
        const ok = await reorderComponents(orderedIds);
        setSaveStatus(ok ? 'saved' : 'error');
        setAnnouncement(
            ok ? t('Form order saved.') : t('Could not save the new order.'),
        );

        return ok;
    };

    const selected = items.find((item) => item.id === selectedId) ?? null;

    const handleSave = async (input: {
        type: QuestionnaireItemType;
        title: string;
        instructions: string | null;
        configuration: QuestionnaireItemConfiguration;
    }): Promise<boolean> => {
        if (!selected) {
            return false;
        }

        setSaveStatus('saving');
        const updated = await updateComponent(selected.id, input);

        if (!updated) {
            setSaveStatus('error');
            setAnnouncement(t('Could not save field settings.'));

            return false;
        }

        setItems((previous) =>
            previous.map((item) => (item.id === updated.id ? updated : item)),
        );
        setSaveStatus('saved');
        setAnnouncement(t('Field settings saved.'));

        return true;
    };

    const handleDestroy = async (
        documentRequestId: number,
    ): Promise<boolean> => {
        setSaveStatus('saving');
        const ok = await destroyComponent(documentRequestId);

        if (!ok) {
            setSaveStatus('error');
            setAnnouncement(t('Could not delete the component. Try again.'));

            return false;
        }

        const remaining = items
            .filter((item) => item.id !== documentRequestId)
            .map((item, index) => ({ ...item, sort_order: index }));

        setItems(remaining);
        setSelectedId((current) => {
            if (current !== documentRequestId) {
                return current;
            }

            return remaining[0]?.id ?? null;
        });
        setSaveStatus('saved');
        setAnnouncement(t('Component deleted.'));

        return true;
    };

    return {
        items,
        setItems,
        selectedId,
        setSelectedId,
        selected,
        saveStatus,
        announcement,
        isInsertPending,
        addAt,
        handleReorder,
        handleSave,
        handleDestroy,
    };
}
