import { DndContext, DragOverlay } from '@dnd-kit/core';
import {
    SortableContext,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { useMemo } from 'react';
import { useMediaQuery } from 'usehooks-ts';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import DesktopRequiredGate from '@/features/document-requests/staff/builder/desktop-required-gate';
import QuestionnaireBuilderCanvas from '@/features/document-requests/staff/builder/questionnaire-builder-canvas';
import {
    BuilderPane,
    CanvasItemOverlay,
    PaletteOverlay,
} from '@/features/document-requests/staff/builder/questionnaire-builder-chrome';
import QuestionnaireBuilderPalette from '@/features/document-requests/staff/builder/questionnaire-builder-palette';
import QuestionnaireBuilderSettings from '@/features/document-requests/staff/builder/questionnaire-builder-settings';
import {
    builderCollisionDetection,
    useQuestionnaireBuilderDnD,
} from '@/features/document-requests/staff/builder/use-questionnaire-builder-dnd';
import { useQuestionnaireBuilderState } from '@/features/document-requests/staff/builder/use-questionnaire-builder-state';
import type { DocumentRequest } from '@/features/document-requests/types';
import type { DossierStatus } from '@/features/dossiers/types';
import type { ApplyTemplateOption } from '@/features/questionnaires/types';
import { useTranslation } from '@/hooks/use-translation';

/** Desktop three-pane layout (matches Tailwind `xl`). */
const DESKTOP_QUERY = '(min-width: 1280px)';

/**
 * Form builder composition root: palette, canvas, and settings with shared DnD.
 * Desktop-only — narrower viewports see an honest gate instead of broken sheets.
 */
export default function QuestionnaireBuilder({
    dossierId,
    dossierStatus,
    documentRequests,
    templates,
    canEdit,
    backHref,
}: {
    dossierId: number;
    dossierStatus: DossierStatus;
    documentRequests: DocumentRequest[];
    templates: ApplyTemplateOption[];
    canEdit: boolean;
    backHref: string;
}) {
    const { t } = useTranslation();
    // Prefer desktop on first paint so wide screens do not flash the gate.
    const isDesktop = useMediaQuery(DESKTOP_QUERY, {
        defaultValue: true,
        initializeWithValue: true,
    });
    const state = useQuestionnaireBuilderState({
        dossierId,
        documentRequests,
        canEdit,
    });

    // Stable id list for SortableContext — only changes when items change.
    const sortableIds = useMemo(
        () => state.items.map((item) => item.id),
        [state.items],
    );

    const { activeDrag, sensors, handleDragStart, handleDragEnd } =
        useQuestionnaireBuilderDnD({
            items: state.items,
            setItems: state.setItems,
            canEdit: canEdit && !state.isInsertPending,
            onInsert: state.addAt,
            onReorder: state.handleReorder,
        });

    const activeCanvasItem =
        activeDrag?.kind === 'canvas'
            ? (state.items.find((item) => item.id === activeDrag.id) ?? null)
            : null;

    if (!isDesktop) {
        return <DesktopRequiredGate backHref={backHref} />;
    }

    const canEditStructure =
        canEdit && dossierStatus !== 'completed' && !state.isInsertPending;

    const palette = (
        <QuestionnaireBuilderPalette
            dossierId={dossierId}
            templates={templates}
            canEdit={canEditStructure}
            insertAfterTitle={state.selected?.title ?? null}
            onAdd={(type) => {
                state.addAt(type);
            }}
        />
    );

    const settings = (
        <QuestionnaireBuilderSettings
            documentRequest={state.selected}
            canEdit={canEdit && !state.isInsertPending}
            saveStatus={state.saveStatus}
            onSave={state.handleSave}
        />
    );

    return (
        // Fill the viewport-locked layout; columns scroll internally.
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <div className="sr-only" aria-live="polite" aria-atomic="true">
                {state.announcement}
            </div>

            <DndContext
                sensors={sensors}
                collisionDetection={builderCollisionDetection}
                onDragStart={handleDragStart}
                onDragEnd={(event) => {
                    void handleDragEnd(event);
                }}
            >
                {/* grid-rows-1 so columns fill the flex-1 height (auto rows stay content-sized). */}
                <div className="grid min-h-0 flex-1 grid-cols-[16rem_minmax(0,1fr)_20rem] grid-rows-1 items-stretch gap-4">
                    <BuilderPane>{palette}</BuilderPane>

                    <SortableContext
                        items={sortableIds}
                        strategy={verticalListSortingStrategy}
                    >
                        <QuestionnaireBuilderCanvas
                            dossierId={dossierId}
                            items={state.items}
                            selectedId={state.selectedId}
                            canEdit={canEditStructure}
                            pendingInsert={state.pendingInsert}
                            onSelect={state.setSelectedId}
                            onAdd={(type) => {
                                state.addAt(type);
                            }}
                        />
                    </SortableContext>

                    <BuilderPane>{settings}</BuilderPane>
                </div>

                <DragOverlay>
                    {activeDrag?.kind === 'palette' ? (
                        <PaletteOverlay type={activeDrag.type} />
                    ) : null}
                    {activeCanvasItem ? (
                        <CanvasItemOverlay
                            title={activeCanvasItem.title}
                            typeLabel={t(
                                getQuestionnaireItemTypeDefinition(
                                    activeCanvasItem.type,
                                ).label,
                            )}
                        />
                    ) : null}
                </DragOverlay>
            </DndContext>
        </div>
    );
}
