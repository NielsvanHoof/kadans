/**
 * Questionnaire template types.
 *
 * Payload shapes are generated from the PHP Data objects. Only the two
 * apply-picker types are refined, because their `configuration` is a free-form
 * JSON column in PHP while the builder needs its structure.
 */
import type { QuestionnaireItemConfiguration } from '@/features/document-requests/types';

/** Category option for template forms. */
export type TemplateCategoryOption = {
    value: App.Questionnaires.Enums.QuestionnaireTemplateCategory;
    label: string;
};

export type TemplateSummary =
    App.Questionnaires.Data.Templates.QuestionnaireTemplateRowData;

/** One item inside a template editor. */
export type TemplateItem =
    App.Questionnaires.Data.Templates.QuestionnaireTemplateItemData;

/** Full template payload for the show/editor page. */
export type TemplateDetail =
    App.Questionnaires.Data.Templates.QuestionnaireTemplateShowData;

/** Template item payload embedded on the builder apply picker. */
export type ApplyTemplateItem = Omit<
    App.Questionnaires.Data.Templates.QuestionnaireTemplateItemOptionData,
    'configuration'
> & {
    configuration: QuestionnaireItemConfiguration | null;
};

/** Template option on the dossier apply picker. */
export type ApplyTemplateOption = Omit<
    App.Questionnaires.Data.Templates.QuestionnaireTemplateOptionData,
    'items'
> & {
    items: ApplyTemplateItem[];
};

/**
 * AI-suggested checklist item from the draft-items endpoint. Never persisted
 * directly; only used to seed the existing "Add item" form.
 */
export type DraftedChecklistItem =
    App.Questionnaires.Data.ChecklistDraft.DraftedChecklistItemData;
