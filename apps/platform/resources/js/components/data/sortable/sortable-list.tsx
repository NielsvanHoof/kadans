import {
    closestCenter,
    DndContext,
    type DragEndEvent,
    type DraggableAttributes,
    type DraggableSyntheticListeners,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { applyIdOrder, reorderIds, sameIdOrder } from '@/lib/reorder';
import { cn } from '@/lib/utils';

type SortableListProps<T extends { id: number }> = {
    items: T[];
    enabled?: boolean;
    onReorder: (orderedIds: number[]) => Promise<boolean>;
    className?: string;
    /** Render one row; DragHandle is only for the grip (keeps inputs editable). */
    renderItem: (
        item: T,
        controls: { DragHandle: () => ReactNode },
    ) => ReactNode;
};

type DragHandleControlProps = {
    label: string;
    attributes?: DraggableAttributes;
    // Public dnd-kit export (SyntheticListenerMap is internal-only).
    listeners?: DraggableSyntheticListeners;
    static?: boolean;
};

/** Module-level grip so SortableRow does not define a new component each render. */
function SortableDragHandle({
    label,
    attributes,
    listeners,
    static: isStatic = false,
}: DragHandleControlProps) {
    return (
        <Button
            type="button"
            size="icon"
            variant="ghost"
            className={cn(
                'touch-none',
                isStatic
                    ? 'pointer-events-none cursor-grab'
                    : 'cursor-grab active:cursor-grabbing',
            )}
            aria-label={label}
            tabIndex={isStatic ? -1 : undefined}
            {...attributes}
            {...listeners}
        >
            <GripVertical />
        </Button>
    );
}

/**
 * Vertical drag-and-drop list. Posts order via onReorder after a drop.
 *
 * DnD mounts only after hydration — dnd-kit accessibility IDs differ between
 * SSR and the client and would otherwise cause hydration mismatches.
 */
export default function SortableList<T extends { id: number }>({
    items,
    enabled = true,
    onReorder,
    className,
    renderItem,
}: SortableListProps<T>) {
    const { t } = useTranslation();

    // Local order for snappy UI while Inertia reloads props.
    const [orderedItems, setOrderedItems] = useState(items);
    const [isPersisting, setIsPersisting] = useState(false);
    // false on SSR + first client paint so markup matches, then enable DnD.
    const [isClient, setIsClient] = useState(false);

    useEffect(() => {
        setOrderedItems(items);
    }, [items]);

    useEffect(() => {
        setIsClient(true);
    }, []);

    const sensors = useSensors(
        useSensor(PointerSensor, {
            // Avoid stealing clicks from form controls.
            activationConstraint: { distance: 6 },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const handleDragEnd = async (event: DragEndEvent): Promise<void> => {
        const { active, over } = event;

        if (isPersisting || !over || active.id === over.id) {
            return;
        }

        const currentIds = orderedItems.map((item) => item.id);
        const nextIds = reorderIds(
            currentIds,
            Number(active.id),
            Number(over.id),
        );

        if (sameIdOrder(currentIds, nextIds)) {
            return;
        }

        const previousItems = orderedItems;

        setOrderedItems(applyIdOrder(previousItems, nextIds));
        setIsPersisting(true);

        try {
            const persisted = await onReorder(nextIds);

            if (!persisted) {
                setOrderedItems(previousItems);
            }
        } catch {
            setOrderedItems(previousItems);
        } finally {
            setIsPersisting(false);
        }
    };

    const dragLabel = t('Drag to reorder');

    // Static list until the client mounts (and when reordering is disabled).
    if (!enabled || !isClient || isPersisting) {
        return (
            <div className={className}>
                {orderedItems.map((item) => (
                    <div key={item.id}>
                        {renderItem(item, {
                            DragHandle: () =>
                                enabled ? (
                                    <SortableDragHandle
                                        label={dragLabel}
                                        static
                                    />
                                ) : null,
                        })}
                    </div>
                ))}
            </div>
        );
    }

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
        >
            <SortableContext
                items={orderedItems.map((item) => item.id)}
                strategy={verticalListSortingStrategy}
            >
                <div className={className}>
                    {orderedItems.map((item) => (
                        <SortableRow
                            key={item.id}
                            id={item.id}
                            label={dragLabel}
                        >
                            {(DragHandle) => renderItem(item, { DragHandle })}
                        </SortableRow>
                    ))}
                </div>
            </SortableContext>
        </DndContext>
    );
}

function SortableRow({
    id,
    label,
    children,
}: {
    id: number;
    label: string;
    children: (DragHandle: () => ReactNode) => ReactNode;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    const prefersReducedMotion =
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const style = {
        transform: CSS.Transform.toString(transform),
        transition: prefersReducedMotion ? undefined : transition,
    };

    const DragHandle = () => (
        <SortableDragHandle
            label={label}
            attributes={attributes}
            listeners={listeners}
        />
    );

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={cn(
                isDragging && 'relative z-10 bg-background shadow-md',
            )}
        >
            {children(DragHandle)}
        </div>
    );
}
