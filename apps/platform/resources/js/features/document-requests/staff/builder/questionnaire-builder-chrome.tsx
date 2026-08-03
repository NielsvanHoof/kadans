import type { ReactNode } from 'react';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import { getQuestionnaireItemTypeBuilderRenderer } from '@/features/document-requests/questionnaire-item-type-registry';
import type { QuestionnaireItemType } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/** Full-height, independently scrolling side pane for desktop. */
export function BuilderPane({ children }: { children: ReactNode }) {
    return (
        <div className="h-full min-h-0 overflow-y-auto overscroll-contain">
            {children}
        </div>
    );
}

export function PaletteOverlay({ type }: { type: QuestionnaireItemType }) {
    const { t } = useTranslation();
    const definition = getQuestionnaireItemTypeDefinition(type);
    const { icon: Icon } = getQuestionnaireItemTypeBuilderRenderer(type);

    return (
        <div className="flex items-center gap-2 rounded-xl border border-primary bg-card px-3 py-2 shadow-lg ring-2 ring-primary/25">
            <Icon className="size-4 text-primary" aria-hidden="true" />
            <span className="text-sm font-medium">{t(definition.label)}</span>
        </div>
    );
}

/** Drag preview for an existing canvas field while reordering. */
export function CanvasItemOverlay({
    title,
    typeLabel,
}: {
    title: string;
    typeLabel: string;
}) {
    return (
        <div className="flex max-w-sm items-center gap-2 rounded-xl border border-primary bg-card px-3 py-2 shadow-lg ring-2 ring-primary/25">
            <span className="min-w-0 flex-1 truncate text-sm font-medium">
                {title}
            </span>
            <span className="shrink-0 text-xs text-muted-foreground">
                {typeLabel}
            </span>
        </div>
    );
}
