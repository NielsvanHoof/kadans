import {
    AlignLeft,
    Calendar,
    CheckSquare,
    FileUp,
    Hash,
    type LucideIcon,
    Type,
} from 'lucide-react';
import type { ComponentType } from 'react';
import {
    BuilderBooleanPreview,
    BuilderDatePreview,
    BuilderFilePreview,
    BuilderNumberPreview,
    BuilderTextareaPreview,
    BuilderTextPreview,
} from '@/features/document-requests/staff/builder/builder-field-previews';
import type { QuestionnaireItemType } from '@/features/document-requests/types';

export type BuilderFieldPreviewProps = {
    title: string;
    instructions?: string | null;
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
} satisfies Record<QuestionnaireItemType, QuestionnaireItemTypeBuilderRenderer>;

export function getQuestionnaireItemTypeBuilderRenderer(
    type: QuestionnaireItemType,
): QuestionnaireItemTypeBuilderRenderer {
    return questionnaireItemTypeBuilderRenderers[type];
}
