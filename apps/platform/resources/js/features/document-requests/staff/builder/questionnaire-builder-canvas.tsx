import { useDroppable } from '@dnd-kit/core';
import EmptyState from '@/components/primitives/empty-state';
import { Button } from '@/components/ui/button';
import { getQuestionnaireItemTypeDefinitions } from '@/features/document-requests/item-types/definitions';
import { getQuestionnaireItemTypeBuilderRenderer } from '@/features/document-requests/questionnaire-item-type-registry';
import QuestionnaireBuilderItem from '@/features/document-requests/staff/builder/questionnaire-builder-item';
import type {
    DocumentRequest,
    QuestionnaireItemType,
} from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export const BUILDER_CANVAS_DROPPABLE_ID = 'builder-canvas';

/**
 * Droppable canvas surface that renders sortable builder items.
 * DnD context and SortableContext live in the parent builder root.
 */
export default function QuestionnaireBuilderCanvas({
    items,
    selectedId,
    canEdit,
    onSelect,
    onAdd,
    onDestroy,
}: {
    items: DocumentRequest[];
    selectedId: number | null;
    canEdit: boolean;
    onSelect: (id: number) => void;
    onAdd: (type: QuestionnaireItemType) => void;
    onDestroy: (id: number) => Promise<boolean>;
}) {
    const { t } = useTranslation();
    const { setNodeRef, isOver } = useDroppable({
        id: BUILDER_CANVAS_DROPPABLE_ID,
    });
    const quickAddTypes = getQuestionnaireItemTypeDefinitions(t).slice(0, 4);

    const isEmpty = items.length === 0;

    return (
        <section
            className="flex h-full min-h-0 min-w-0 flex-col"
            aria-label={t('Client form canvas')}
        >
            {/* Sticky toolbar stays visible while the field list scrolls. */}
            <div className="bg-background/95 sticky top-0 z-10 mb-3 space-y-1 pb-2 backdrop-blur-sm">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 className="text-sm font-semibold tracking-tight text-pretty">
                        {t('Client form')}
                    </h3>
                    <p className="font-data text-muted-foreground text-xs">
                        {t(':count fields', { count: items.length })}
                    </p>
                </div>
                <p className="text-muted-foreground text-xs">
                    {t(
                        'This is the form the client will fill in. Drag components here to build it.',
                    )}
                </p>
            </div>

            <div
                ref={setNodeRef}
                className={cn(
                    'min-h-64 flex-1 overflow-y-auto overscroll-contain rounded-2xl border border-dashed p-2 transition-colors duration-150 motion-reduce:transition-none md:p-3',
                    isOver
                        ? 'border-primary bg-primary/5'
                        : 'border-border/70 bg-muted/20',
                )}
            >
                {isEmpty ? (
                    <EmptyState
                        variant="panel"
                        title={t('Drop a component to start the form')}
                        description={t(
                            'Drag from the Components panel, or add one below to get started.',
                        )}
                        action={
                            canEdit ? (
                                <div className="flex flex-wrap justify-center gap-2">
                                    {quickAddTypes.map((definition) => {
                                        const { icon: Icon } =
                                            getQuestionnaireItemTypeBuilderRenderer(
                                                definition.value,
                                            );

                                        return (
                                            <Button
                                                key={definition.value}
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    onAdd(definition.value)
                                                }
                                            >
                                                <Icon aria-hidden="true" />
                                                {definition.label}
                                            </Button>
                                        );
                                    })}
                                </div>
                            ) : undefined
                        }
                    />
                ) : (
                    <ol className="space-y-2">
                        {items.map((item, index) => (
                            <QuestionnaireBuilderItem
                                key={item.id}
                                documentRequest={item}
                                index={index}
                                selected={selectedId === item.id}
                                canEdit={canEdit}
                                onSelect={onSelect}
                                onDestroy={onDestroy}
                            />
                        ))}
                    </ol>
                )}
            </div>
        </section>
    );
}
