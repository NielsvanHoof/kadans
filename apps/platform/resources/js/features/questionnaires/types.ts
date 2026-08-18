/**
 * Questionnaire template types.
 *
 * Payload shapes are generated from the PHP Data objects. Only the two
 * apply-picker types are refined, because their `configuration` is a free-form
 * JSON column in PHP while the builder needs its structure.
 */
import type {
    QuestionnaireItemConfiguration,
    QuestionnaireItemType,
} from '@/features/document-requests/types';

/** Category option for template forms. */
export type TemplateCategoryOption = {
    value: App.Questionnaires.Enums.QuestionnaireTemplateCategory;
    label: string;
};

/** Compact template row for index / apply pickers. */
export type TemplateSummary =
    App.Questionnaires.Data.QuestionnaireTemplateRowData;

/** One item inside a template editor. */
export type TemplateItem =
    App.Questionnaires.Data.QuestionnaireTemplateItemData;

/** Full template payload for the show/editor page. */
export type TemplateDetail =
    App.Questionnaires.Data.QuestionnaireTemplateShowData;

/** Template item payload embedded on the builder apply picker. */
export type ApplyTemplateItem = Omit<
    App.Questionnaires.Data.QuestionnaireTemplateItemOptionData,
    'configuration'
> & {
    configuration: QuestionnaireItemConfiguration | null;
};

/** Template option on the dossier apply picker. */
export type ApplyTemplateOption = Omit<
    App.Questionnaires.Data.QuestionnaireTemplateOptionData,
    'items'
> & {
    items: ApplyTemplateItem[];
};

/**
 * AI-suggested checklist item from the draft-items endpoint — hand-typed
 * since the response is a plain JSON array, not a generated Data payload.
 * Never persisted directly; only used to seed the existing "Add item" form.
 */
export type DraftedChecklistItem = {
    title: string;
    instructions: string;
    type: QuestionnaireItemType;
};
