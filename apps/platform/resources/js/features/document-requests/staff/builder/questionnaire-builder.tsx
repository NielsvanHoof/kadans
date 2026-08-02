import { DndContext, DragOverlay } from '@dnd-kit/core';
import {
    SortableContext,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { router } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useMediaQuery } from 'usehooks-ts';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
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
import { useQuestionnaireBuilderLock } from '@/features/document-requests/staff/builder/use-questionnaire-builder-lock';
import { useQuestionnaireBuilderState } from '@/features/document-requests/staff/builder/use-questionnaire-builder-state';
import type { DocumentRequest } from '@/features/document-requests/types';
import type {
    DossierBuilderLock,
    DossierStatus,
} from '@/features/dossiers/types';
import type { ApplyTemplateOption } from '@/features/questionnaires/types';
import { useTranslation } from '@/hooks/use-translation';

/** Desktop three-pane layout (matches Tailwind `xl`). */
const DESKTOP_QUERY = '(min-width: 1280px)';

/**
 * Form builder composition root: palette, canvas, and settings with shared DnD.
 * Draft edits stay local until Save; desktop-only layout.
 */
export default function QuestionnaireBuilder({
    dossierId,
    dossierStatus,
    documentRequests,
    templates,
    canManage,
    initialLock,
    backHref,
}: {
    dossierId: number;
    dossierStatus: DossierStatus;
    documentRequests: DocumentRequest[];
    templates: ApplyTemplateOption[];
    canManage: boolean;
    initialLock: DossierBuilderLock;
    backHref: string;
}) {
    const { t } = useTranslation();
    // Prefer desktop on first paint so wide screens do not flash the gate.
    const isDesktop = useMediaQuery(DESKTOP_QUERY, {
        defaultValue: true,
        initializeWithValue: true,
    });

    const lockEnabled = canManage && dossierStatus !== 'completed';
    const {
        lock,
        canEdit: holdsLock,
        releaseLock,
    } = useQuestionnaireBuilderLock({
        dossierId,
        initialLock,
        enabled: lockEnabled,
    });

    const canEditStructure = holdsLock;
    const state = useQuestionnaireBuilderState({
        dossierId,
        documentRequests,
        canEdit: canEditStructure,
    });
    const [leaveOpen, setLeaveOpen] = useState(false);
    const [pendingHref, setPendingHref] = useState<string | null>(null);
    const allowLeaveRef = useRef(false);

    // Stable id list for SortableContext — only changes when items change.
    const sortableIds = useMemo(
        () => state.items.map((item) => item.id),
        [state.items],
    );

    const { activeDrag, sensors, handleDragStart, handleDragEnd } =
        useQuestionnaireBuilderDnD({
            items: state.items,
            setItems: state.setItems,
            canEdit: canEditStructure,
            onInsert: state.addAt,
            onReorder: state.handleReorder,
        });

    const activeCanvasItem =
        activeDrag?.kind === 'canvas'
            ? (state.items.find((item) => item.id === activeDrag.id) ?? null)
            : null;

    // Warn on browser refresh / tab close when the draft is dirty.
    useEffect(() => {
        if (!state.isDirty) {
            return;
        }

        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', handleBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
        };
    }, [state.isDirty]);

    // Intercept Inertia visits (including Back) when the draft has unsaved changes.
    // Release the lock only when the visit actually proceeds (not when cancelled).
    useEffect(() => {
        const builderPath = `/dossiers/${dossierId}/builder`;

        return router.on('before', (event) => {
            if (allowLeaveRef.current) {
                allowLeaveRef.current = false;

                return;
            }

            const visit = event.detail.visit;

            // Allow non-GET (e.g. Save sync / heartbeat) — only block page navigations.
            if (visit.method !== 'get') {
                return;
            }

            const href =
                typeof visit.url === 'string'
                    ? visit.url
                    : visit.url.toString();

            const leavingBuilder = !href.includes(builderPath);

            if (state.isDirty) {
                event.preventDefault();
                setPendingHref(href);
                setLeaveOpen(true);

                return;
            }

            if (leavingBuilder) {
                releaseLock();
            }
        });
    }, [state.isDirty, dossierId, releaseLock]);

    if (!isDesktop) {
        return <DesktopRequiredGate backHref={backHref} />;
    }

    const leaveWithoutSaving = () => {
        const href = pendingHref ?? backHref;
        setLeaveOpen(false);
        setPendingHref(null);
        releaseLock();
        allowLeaveRef.current = true;
        router.visit(href);
    };

    const lockedByName = lock.holder?.name ?? null;
    const lockBanner =
        lockEnabled && !holdsLock && lockedByName
            ? t('Locked by :name', { name: lockedByName })
            : lockEnabled && !holdsLock
              ? t('This form is locked by another user.')
              : null;

    const palette = (
        <QuestionnaireBuilderPalette
            templates={templates}
            canEdit={canEditStructure}
            insertAfterTitle={state.selected?.title ?? null}
            onAdd={(type) => {
                state.addAt(type);
            }}
            onApplyTemplate={state.applyTemplate}
        />
    );

    const settings = (
        <QuestionnaireBuilderSettings
            documentRequest={state.selected}
            siblings={state.items}
            canEdit={canEditStructure}
            saveStatus={state.saveStatus}
            onSave={state.handleSaveField}
        />
    );

    return (
        // Fill the viewport-locked layout; columns scroll internally.
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            {lockBanner ? (
                <Alert className="border-amber-500/30 bg-amber-500/10 text-amber-950 dark:text-amber-100">
                    <LockKeyhole aria-hidden />
                    <AlertTitle>{lockBanner}</AlertTitle>
                    <AlertDescription className="text-amber-950/80 dark:text-amber-100/80">
                        {t(
                            'You can view the last saved form. Editing unlocks when they leave or the lock expires.',
                        )}
                    </AlertDescription>
                </Alert>
            ) : (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p
                        className="text-xs text-muted-foreground"
                        aria-live="polite"
                    >
                        {state.isDirty
                            ? t('Unsaved changes')
                            : state.saveStatus === 'saved'
                              ? t('All changes saved')
                              : null}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        isDisabled={
                            !canEditStructure ||
                            !state.isDirty ||
                            state.saveStatus === 'saving'
                        }
                        onClick={() => {
                            void state.saveDraft();
                        }}
                    >
                        {state.saveStatus === 'saving'
                            ? t('Saving…')
                            : t('Save form')}
                    </Button>
                </div>
            )}

            <div className="sr-only" aria-live="polite" aria-atomic="true">
                {state.announcement}
            </div>

            <DndContext
                sensors={sensors}
                collisionDetection={builderCollisionDetection}
                onDragStart={handleDragStart}
                onDragEnd={handleDragEnd}
            >
                {/* grid-rows-1 so columns fill the flex-1 height (auto rows stay content-sized). */}
                <div className="grid min-h-0 flex-1 grid-cols-[16rem_minmax(0,1fr)_20rem] grid-rows-1 items-stretch gap-4">
                    <BuilderPane>{palette}</BuilderPane>

                    <SortableContext
                        items={sortableIds}
                        strategy={verticalListSortingStrategy}
                    >
                        <QuestionnaireBuilderCanvas
                            items={state.items}
                            selectedId={state.selectedId}
                            canEdit={canEditStructure}
                            onSelect={state.setSelectedId}
                            onAdd={(type) => {
                                state.addAt(type);
                            }}
                            onDestroy={state.handleDestroy}
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

            {/* AlertDialogContent *is* the modal in React Aria, so the open
                state goes straight on it — no outer AlertDialog wrapper. */}
            <AlertDialogContent isOpen={leaveOpen} onOpenChange={setLeaveOpen}>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {t('Discard unsaved changes?')}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {t(
                            'You have unsaved form changes. Leave without saving?',
                        )}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel
                        onPress={() => {
                            setPendingHref(null);
                        }}
                    >
                        {t('Keep editing')}
                    </AlertDialogCancel>
                    <AlertDialogAction onPress={leaveWithoutSaving}>
                        {t('Leave without saving')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </div>
    );
}
