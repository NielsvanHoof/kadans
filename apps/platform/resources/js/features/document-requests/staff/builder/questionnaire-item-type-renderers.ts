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
import type {
    QuestionnaireItemConfiguration,
    QuestionnaireItemType,
} from '@/features/document-requests/types';

export type BuilderFieldPreviewProps = {
    title: string;
    instructions?: string | null;
    configuration?: QuestionnaireItemConfiguration | null;
};

type QuestionnaireItemTypeBuilderRenderer = {
    icon: LucideIcon;
    Preview: ComponentType<BuilderFieldPreviewProps>;
};

const questionnaireItemTypeBuilderRenderers = {
    file: {
        icon: FileUp,
        Preview: BuilderFilePreview,
    },
    text: {
        icon: Type,
        Preview: BuilderTextPreview,
    },
    textarea: {
        icon: AlignLeft,
        Preview: BuilderTextareaPreview,
    },
    date: {
        icon: Calendar,
        Preview: BuilderDatePreview,
    },
    number: {
        icon: Hash,
        Preview: BuilderNumberPreview,
    },
    boolean: {
        icon: CheckSquare,
        Preview: BuilderBooleanPreview,
    },
    choice: {
        icon: CircleDot,
        Preview: BuilderChoicePreview,
    },
    multi_select: {
        icon: ListChecks,
        Preview: BuilderMultiSelectPreview,
    },
} satisfies Record<QuestionnaireItemType, QuestionnaireItemTypeBuilderRenderer>;

export function getQuestionnaireItemTypeBuilderRenderer(
    type: QuestionnaireItemType,
): QuestionnaireItemTypeBuilderRenderer {
    return questionnaireItemTypeBuilderRenderers[type];
}
