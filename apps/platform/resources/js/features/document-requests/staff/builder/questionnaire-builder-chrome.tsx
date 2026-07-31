import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
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

/**
 * Ghost of the compact canvas row while create is in flight.
 * Matches real row chrome so the handoff does not jump; fades in instead of spinning.
 * Announcements stay on the builder root live region — no duplicate aria-live here.
 */
export function BuilderInsertPlaceholder({
    index,
    type,
    title,
}: {
    index: number;
    type: QuestionnaireItemType;
    title: string;
}) {
    const { t } = useTranslation();
    const definition = getQuestionnaireItemTypeDefinition(type);

    return (
        <li
            className="flex animate-in list-none items-center gap-1.5 rounded-xl border border-dashed border-border/70 bg-card/70 px-2 py-1.5 opacity-70 duration-200 fade-in motion-reduce:animate-none"
            aria-busy="true"
        >
            {/* Spacer matches the drag-handle column so the index lines up with real rows. */}
            <span className="size-8 shrink-0" aria-hidden="true" />
            <span className="w-5 shrink-0 font-data text-xs text-muted-foreground/50 tabular-nums">
                {index + 1}
            </span>
            <span className="min-w-0 flex-1 truncate text-sm font-medium">
                {title}
            </span>
            <Badge variant="outline" className="shrink-0">
                {t(definition.label)}
            </Badge>
            <span className="sr-only">{t('Adding component…')}</span>
        </li>
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
