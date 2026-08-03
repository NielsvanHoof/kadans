import { useEffect, useState } from 'react';
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
import { storesAnswerOptionIds } from '@/features/document-requests/types';
import type { ApplyTemplateOption } from '@/features/questionnaires/types';
import { useTranslation } from '@/hooks/use-translation';
import { createUlid } from '@/lib/ulid';

let nextTempId = -1;

function allocateTempId(): number {
    const id = nextTempId;
    nextTempId -= 1;

    return id;
}

function defaultConfiguration(
    type: QuestionnaireItemType,
): QuestionnaireItemConfiguration {
    if (!storesAnswerOptionIds(type)) {
        return { is_required: true };
    }

    return {
        is_required: true,
        options: [
            { id: crypto.randomUUID(), label: 'Option 1' },
            { id: crypto.randomUUID(), label: 'Option 2' },
        ],
    };
}

function createDraftItem(
    type: QuestionnaireItemType,
    title: string,
    sortOrder: number,
    overrides?: Partial<
        Pick<DocumentRequest, 'field_key' | 'instructions' | 'configuration'>
    >,
): DocumentRequest {
    return {
        id: allocateTempId(),
        type,
        field_key: overrides?.field_key ?? createUlid(),
        title,
        instructions: overrides?.instructions ?? null,
        configuration: overrides?.configuration ?? defaultConfiguration(type),
        status: 'pending',
        answer_text: null,
        answer_boolean: null,
        answer_option_ids: null,
        answered_at: null,
        reviewed_at: null,
        reviewed_by_name: null,
        rejection_reason: null,
        sort_order: sortOrder,
        is_visible: true,
        is_applicable: true,
        uploaded_document: null,
    };
}

/**
 * Client-owned builder draft. Mutations stay local until Save syncs to the server.
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
    const { syncDocumentRequests } = useQuestionnaireBuilderActions(dossierId);

    const [items, setItems] = useState(documentRequests);
    const [selectedId, setSelectedId] = useState<number | null>(
        documentRequests[0]?.id ?? null,
    );
    const [saveStatus, setSaveStatus] = useState<BuilderSaveStatus>('idle');
    const [announcement, setAnnouncement] = useState('');
    const [isDirty, setIsDirty] = useState(false);

    // Sync when Inertia replaces page props (rare after draft+Save; e.g. revisit).
    useEffect(() => {
        setItems(documentRequests);
        setIsDirty(false);
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

    const markDirty = () => {
        setIsDirty(true);
        setSaveStatus('idle');
    };

    const addAt = (type: QuestionnaireItemType, position?: number) => {
        if (!canEdit) {
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
        const created = createDraftItem(type, title, insertAt);

        setItems((previous) => {
            const next = [...previous];
            next.splice(insertAt, 0, created);

            return next.map((item, index) => ({
                ...item,
                sort_order: index,
            }));
        });
        setSelectedId(created.id);
        markDirty();
        setAnnouncement(t('Component added.'));
    };

    // DnD already applies the new order via setItems; this only marks dirty.
    const handleReorder = (_orderedIds: number[]): boolean => {
        if (!canEdit) {
            return false;
        }

        markDirty();
        setAnnouncement(t('Form order updated.'));

        return true;
    };

    const selected = items.find((item) => item.id === selectedId) ?? null;

    const handleSaveField = async (input: {
        type: QuestionnaireItemType;
        title: string;
        instructions: string | null;
        configuration: QuestionnaireItemConfiguration;
    }): Promise<boolean> => {
        if (!selected || !canEdit) {
            return false;
        }

        setItems((previous) =>
            previous.map((item) =>
                item.id === selected.id
                    ? {
                          ...item,
                          type: input.type,
                          title: input.title,
                          instructions: input.instructions,
                          configuration: input.configuration,
                      }
                    : item,
            ),
        );
        markDirty();
        setAnnouncement(t('Field settings updated.'));

        return true;
    };

    const handleDestroy = async (
        documentRequestId: number,
    ): Promise<boolean> => {
        if (!canEdit) {
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
        markDirty();
        setAnnouncement(t('Component deleted.'));

        return true;
    };

    const applyTemplate = (template: ApplyTemplateOption) => {
        if (!canEdit || template.items.length === 0) {
            return;
        }

        // Fresh ULIDs per item; remap visibility sources like the server action.
        const fieldKeysByTemplateKey = new Map<string, string>();

        for (const item of template.items) {
            if (item.field_key !== '') {
                fieldKeysByTemplateKey.set(item.field_key, createUlid());
            }
        }

        const appended = template.items.map((item, index) => {
            const fieldKey =
                fieldKeysByTemplateKey.get(item.field_key) ?? createUlid();
            let configuration =
                item.configuration ?? defaultConfiguration(item.type);

            const visibility = configuration.visibility;
            const sourceKey = visibility?.source_field_key;
            const remappedSourceKey = sourceKey
                ? fieldKeysByTemplateKey.get(sourceKey)
                : undefined;

            if (visibility && remappedSourceKey) {
                configuration = {
                    ...configuration,
                    visibility: {
                        ...visibility,
                        source_field_key: remappedSourceKey,
                    },
                };
            }

            return createDraftItem(
                item.type,
                item.title,
                items.length + index,
                {
                    field_key: fieldKey,
                    instructions: item.instructions,
                    configuration,
                },
            );
        });

        setItems((previous) =>
            [...previous, ...appended].map((item, index) => ({
                ...item,
                sort_order: index,
            })),
        );
        setSelectedId(appended[0]?.id ?? selectedId);
        markDirty();
        setAnnouncement(t('Template applied to draft.'));
    };

    const saveDraft = async (): Promise<boolean> => {
        if (!canEdit) {
            return false;
        }

        setSaveStatus('saving');
        setAnnouncement(t('Saving form…'));

        const synced = await syncDocumentRequests(items);

        if (!synced) {
            setSaveStatus('error');
            setAnnouncement(t('Could not save the form. Try again.'));

            return false;
        }

        setItems(synced);
        setIsDirty(false);
        setSaveStatus('saved');
        setAnnouncement(t('Form saved.'));

        // Keep selection on the same field_key after ids become real.
        setSelectedId((current) => {
            if (current === null) {
                return synced[0]?.id ?? null;
            }

            const previous = items.find((item) => item.id === current);
            if (!previous) {
                return synced[0]?.id ?? null;
            }

            return (
                synced.find((item) => item.field_key === previous.field_key)
                    ?.id ??
                synced[0]?.id ??
                null
            );
        });

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
        isDirty,
        addAt,
        handleReorder,
        handleSaveField,
        handleDestroy,
        applyTemplate,
        saveDraft,
    };
}
