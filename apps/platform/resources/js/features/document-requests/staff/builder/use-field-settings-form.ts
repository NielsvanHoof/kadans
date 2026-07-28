import { useEffect, useMemo, useState } from 'react';
import type {
    DocumentRequest,
    QuestionnaireItemConfiguration,
    QuestionnaireItemType,
} from '@/features/document-requests/types';
import { storesAnswerOptionIds } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

export type FieldSettingsSaveInput = {
    type: QuestionnaireItemType;
    title: string;
    instructions: string | null;
    configuration: QuestionnaireItemConfiguration;
};

const DEFAULT_CONFIGURATION: QuestionnaireItemConfiguration = {
    is_required: true,
};

/**
 * Editable state for the selected field, plus the rules for saving it.
 *
 * This lives apart from the panel so the panel is only markup: it owns the draft
 * values, whether they differ from what is saved, and the client-side checks that
 * must pass before a request is sent.
 */
export function useFieldSettingsForm(
    documentRequest: DocumentRequest | null,
    onSave: (input: FieldSettingsSaveInput) => Promise<boolean>,
    canEdit: boolean,
) {
    const { t } = useTranslation();
    const [title, setTitle] = useState(documentRequest?.title ?? '');
    const [instructions, setInstructions] = useState(
        documentRequest?.instructions ?? '',
    );
    const [type, setType] = useState<QuestionnaireItemType>(
        documentRequest?.type ?? 'file',
    );
    const [configuration, setConfiguration] =
        useState<QuestionnaireItemConfiguration>(
            documentRequest?.configuration ?? DEFAULT_CONFIGURATION,
        );
    const [error, setError] = useState<string | null>(null);

    // Selecting another field on the canvas discards any unsaved draft.
    useEffect(() => {
        setTitle(documentRequest?.title ?? '');
        setInstructions(documentRequest?.instructions ?? '');
        setType(documentRequest?.type ?? 'file');
        setConfiguration(
            documentRequest?.configuration ?? DEFAULT_CONFIGURATION,
        );
        setError(null);
    }, [documentRequest]);

    const isDirty = useMemo(() => {
        if (!documentRequest) {
            return false;
        }

        return (
            title.trim() !== documentRequest.title.trim() ||
            instructions.trim() !==
                (documentRequest.instructions?.trim() ?? '') ||
            JSON.stringify(configuration) !==
                JSON.stringify(
                    documentRequest.configuration ?? DEFAULT_CONFIGURATION,
                )
        );
    }, [documentRequest, title, instructions, configuration]);

    // Warn before losing an edit that has not been blurred yet.
    useEffect(() => {
        if (!isDirty) {
            return;
        }

        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', handleBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
        };
    }, [isDirty]);

    const persist = async (next: FieldSettingsSaveInput) => {
        if (!canEdit) {
            return;
        }

        const validationError = findValidationError(next, t);

        if (validationError !== null) {
            setError(validationError);

            return;
        }

        setError(null);

        const saved = await onSave({
            ...next,
            title: next.title.trim(),
            instructions: next.instructions?.trim() || null,
        });

        if (!saved) {
            setError(t('Could not save. Check the fields and try again.'));
        }
    };

    /** Saves the current draft. Used by every on-blur handler in the panel. */
    const saveDraft = () => {
        void persist({ type, title, instructions, configuration });
    };

    /**
     * Applies a configuration change. Controls without a blur event (checkboxes,
     * selects, reordering) pass saveImmediately so the change is not lost.
     */
    const updateConfiguration = (
        next: QuestionnaireItemConfiguration,
        saveImmediately = false,
    ) => {
        setConfiguration(next);

        if (saveImmediately) {
            void persist({ type, title, instructions, configuration: next });
        }
    };

    /**
     * Switching type keeps options and any visibility rule, so flipping between
     * single choice and multi-select does not throw away the client's work.
     */
    const changeType = (
        nextType: QuestionnaireItemType,
        fallbackOptions: QuestionnaireItemConfiguration['options'],
    ) => {
        const nextConfiguration: QuestionnaireItemConfiguration = {
            is_required: configuration.is_required,
            ...(storesAnswerOptionIds(nextType)
                ? { options: configuration.options ?? fallbackOptions }
                : {}),
            ...(configuration.visibility
                ? { visibility: configuration.visibility }
                : {}),
        };

        setType(nextType);
        setConfiguration(nextConfiguration);
        void persist({
            type: nextType,
            title,
            instructions,
            configuration: nextConfiguration,
        });
    };

    return {
        title,
        setTitle,
        instructions,
        setInstructions,
        type,
        changeType,
        configuration,
        updateConfiguration,
        error,
        isDirty,
        saveDraft,
    };
}

/** Returns the first blocking problem with the draft, or null when it can be saved. */
function findValidationError(
    input: FieldSettingsSaveInput,
    t: (key: string) => string,
): string | null {
    if (input.title.trim() === '') {
        return t('Enter a field label.');
    }

    if (
        storesAnswerOptionIds(input.type) &&
        (input.configuration.options?.length ?? 0) < 2
    ) {
        return t('Add at least two options.');
    }

    return null;
}
