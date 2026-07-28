import type { QuestionnaireItemType } from '@/features/document-requests/types';

export type QuestionnaireItemTypeDefinition = {
    value: QuestionnaireItemType;
    /** English translation key — resolve with `t()` before display. */
    label: string;
    /** Short palette description key. */
    description: string;
    /** Default title used when dropping a new component. */
    defaultTitle: string;
};

/**
 * Shared metadata for every questionnaire item type.
 */
const questionnaireItemTypeDefinitionsByType = {
    file: {
        value: 'file',
        label: 'File upload',
        description: 'Ask the client to upload a document.',
        defaultTitle: 'New file upload',
    },
    text: {
        value: 'text',
        label: 'Text answer',
        description: 'Collect a short written answer.',
        defaultTitle: 'New text answer',
    },
    textarea: {
        value: 'textarea',
        label: 'Long text',
        description: 'Collect a longer written answer.',
        defaultTitle: 'New long text answer',
    },
    date: {
        value: 'date',
        label: 'Date',
        description: 'Ask the client to pick a date.',
        defaultTitle: 'New date question',
    },
    number: {
        value: 'number',
        label: 'Number',
        description: 'Collect a numeric answer.',
        defaultTitle: 'New number question',
    },
    boolean: {
        value: 'boolean',
        label: 'Yes / no',
        description: 'Ask a yes or no confirmation.',
        defaultTitle: 'New yes / no question',
    },
    choice: {
        value: 'choice',
        label: 'Single choice',
        description: 'Let the client choose one option.',
        defaultTitle: 'New single choice question',
    },
    multi_select: {
        value: 'multi_select',
        label: 'Multiple choice',
        description: 'Let the client choose one or more options.',
        defaultTitle: 'New multiple choice question',
    },
} satisfies Record<QuestionnaireItemType, QuestionnaireItemTypeDefinition>;

/**
 * Ordered definitions with English label keys (not yet translated).
 */
export const questionnaireItemTypeDefinitions = Object.values(
    questionnaireItemTypeDefinitionsByType,
);

/**
 * Ordered definitions with labels resolved through the translation function.
 */
export function getQuestionnaireItemTypeDefinitions(
    t: (key: string) => string,
): QuestionnaireItemTypeDefinition[] {
    return questionnaireItemTypeDefinitions.map((definition) => ({
        ...definition,
        label: t(definition.label),
        description: t(definition.description),
        defaultTitle: t(definition.defaultTitle),
    }));
}

export function getQuestionnaireItemTypeDefinition(
    type: QuestionnaireItemType,
): QuestionnaireItemTypeDefinition {
    return questionnaireItemTypeDefinitionsByType[type];
}
