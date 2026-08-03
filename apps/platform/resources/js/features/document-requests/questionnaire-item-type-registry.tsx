import {
    AlignLeft,
    Calendar,
    CheckSquare,
    CircleDot,
    FileUp,
    Hash,
    ListChecks,
    type LucideIcon,
    Type,
} from 'lucide-react';
import type { ComponentType } from 'react';
import {
    BuilderBooleanPreview,
    BuilderChoicePreview,
    BuilderDatePreview,
    BuilderFilePreview,
    BuilderMultiSelectPreview,
    BuilderNumberPreview,
    BuilderTextareaPreview,
    BuilderTextPreview,
} from '@/features/document-requests/staff/builder/builder-field-previews';
import {
    StaffBooleanRequestContent,
    StaffChoiceRequestContent,
    type StaffDocumentRequestTypeProps,
    StaffFileRequestContent,
    StaffMultiSelectRequestContent,
    StaffTextRequestContent,
} from '@/features/document-requests/staff/review/document-request-type-content';
import {
    type StaffAnswerFormProps,
    StaffBooleanAnswerForm,
    StaffTextAnswerForm,
} from '@/features/document-requests/staff/review/staff-answer-forms';
import {
    StaffChoiceAnswerForm,
    StaffMultiSelectAnswerForm,
} from '@/features/document-requests/staff/review/staff-option-answer-forms';
import type {
    QuestionnaireItemConfiguration,
    QuestionnaireItemType,
} from '@/features/document-requests/types';

/**
 * Staff-side questionnaire item-type wiring (builder + review + answer forms).
 *
 * Values/capabilities: `item-types/generated.ts` (from PHP enum).
 * Portal content: `item-types/portal-registry.ts` (separate so the guest
 * portal chunk never imports staff UI).
 *
 * Both registries use `satisfies Record<QuestionnaireItemType, …>` so a new
 * PHP enum case fails TypeScript until every surface is filled.
 */

export type BuilderFieldPreviewProps = {
    title: string;
    instructions?: string | null;
    configuration?: QuestionnaireItemConfiguration | null;
};

type StaffDocumentRequestTypeContent =
    ComponentType<StaffDocumentRequestTypeProps>;
type StaffAnswerForm = ComponentType<StaffAnswerFormProps>;

export type QuestionnaireItemTypeStaffRegistryEntry = {
    icon: LucideIcon;
    BuilderPreview: ComponentType<BuilderFieldPreviewProps>;
    StaffContent: StaffDocumentRequestTypeContent;
    /** Null when the type is answered by upload (file), not a form. */
    StaffAnswerForm: StaffAnswerForm | null;
};

const questionnaireItemTypeStaffRegistry = {
    file: {
        icon: FileUp,
        BuilderPreview: BuilderFilePreview,
        StaffContent: StaffFileRequestContent,
        StaffAnswerForm: null,
    },
    text: {
        icon: Type,
        BuilderPreview: BuilderTextPreview,
        StaffContent: StaffTextRequestContent,
        StaffAnswerForm: StaffTextAnswerForm,
    },
    textarea: {
        icon: AlignLeft,
        BuilderPreview: BuilderTextareaPreview,
        StaffContent: StaffTextRequestContent,
        StaffAnswerForm: StaffTextAnswerForm,
    },
    date: {
        icon: Calendar,
        BuilderPreview: BuilderDatePreview,
        StaffContent: StaffTextRequestContent,
        StaffAnswerForm: StaffTextAnswerForm,
    },
    number: {
        icon: Hash,
        BuilderPreview: BuilderNumberPreview,
        StaffContent: StaffTextRequestContent,
        StaffAnswerForm: StaffTextAnswerForm,
    },
    boolean: {
        icon: CheckSquare,
        BuilderPreview: BuilderBooleanPreview,
        StaffContent: StaffBooleanRequestContent,
        StaffAnswerForm: StaffBooleanAnswerForm,
    },
    choice: {
        icon: CircleDot,
        BuilderPreview: BuilderChoicePreview,
        StaffContent: StaffChoiceRequestContent,
        StaffAnswerForm: StaffChoiceAnswerForm,
    },
    multi_select: {
        icon: ListChecks,
        BuilderPreview: BuilderMultiSelectPreview,
        StaffContent: StaffMultiSelectRequestContent,
        StaffAnswerForm: StaffMultiSelectAnswerForm,
    },
} satisfies Record<
    QuestionnaireItemType,
    QuestionnaireItemTypeStaffRegistryEntry
>;

export function getQuestionnaireItemTypeRegistryEntry(
    type: QuestionnaireItemType,
): QuestionnaireItemTypeStaffRegistryEntry {
    return questionnaireItemTypeStaffRegistry[type];
}

/** Builder palette / canvas: icon + field preview. */
export function getQuestionnaireItemTypeBuilderRenderer(
    type: QuestionnaireItemType,
): {
    icon: LucideIcon;
    Preview: ComponentType<BuilderFieldPreviewProps>;
} {
    const entry = getQuestionnaireItemTypeRegistryEntry(type);

    return {
        icon: entry.icon,
        Preview: entry.BuilderPreview,
    };
}

export function getQuestionnaireItemTypeStaffContent(
    type: QuestionnaireItemType,
): StaffDocumentRequestTypeContent {
    return getQuestionnaireItemTypeRegistryEntry(type).StaffContent;
}

export function getStaffAnswerForm(
    type: QuestionnaireItemType,
): StaffAnswerForm | null {
    return getQuestionnaireItemTypeRegistryEntry(type).StaffAnswerForm;
}
