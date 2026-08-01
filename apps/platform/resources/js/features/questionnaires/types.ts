import type {
    QuestionnaireItemConfiguration,
    QuestionnaireItemType,
} from '@/features/document-requests/types';

/** Category option for template forms. */
export type TemplateCategoryOption = {
    value: string;
    label: string;
};

/** Compact template row for index / apply pickers. */
export type TemplateSummary = {
    id: number;
    name: string;
    description?: string | null;
    category: string;
    category_label: string;
    items_count: number;
    is_system: boolean;
};

/** One item inside a template editor. */
export type TemplateItem = {
    id: number;
    type: QuestionnaireItemType;
    title: string;
    instructions: string | null;
    sort_order: number;
};

/** Full template payload for the show/editor page. */
export type TemplateDetail = {
    id: number;
    name: string;
    description: string | null;
    category: string;
    category_label: string;
    is_system: boolean;
    items: TemplateItem[];
};

/** Template item payload embedded on the builder apply picker. */
export type ApplyTemplateItem = {
    type: QuestionnaireItemType;
    field_key: string;
    title: string;
    instructions: string | null;
    configuration: QuestionnaireItemConfiguration | null;
    sort_order: number;
};

/** Template option on the dossier apply picker. */
export type ApplyTemplateOption = {
    id: number;
    name: string;
    category_label: string;
    items_count: number;
    is_system: boolean;
    items: ApplyTemplateItem[];
};
