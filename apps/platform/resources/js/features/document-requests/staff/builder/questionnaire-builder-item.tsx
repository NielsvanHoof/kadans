import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, Trash2 } from 'lucide-react';
import { useMediaQuery } from 'usehooks-ts';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import { getQuestionnaireItemTypeBuilderRenderer } from '@/features/document-requests/questionnaire-item-type-registry';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

export default function QuestionnaireBuilderItem({
    documentRequest,
    index,
    selected,
    canEdit,
    onSelect,
    onDestroy,
}: {
    documentRequest: DocumentRequest;
    index: number;
    selected: boolean;
    canEdit: boolean;
    onSelect: (id: number) => void;
    onDestroy: (id: number) => Promise<boolean>;
}) {
    const { t } = useTranslation();
    const prefersReducedMotion = useMediaQuery(REDUCED_MOTION_QUERY);
    const definition = getQuestionnaireItemTypeDefinition(documentRequest.type);
    const { Preview: BuilderPreview } = getQuestionnaireItemTypeBuilderRenderer(
        documentRequest.type,
    );
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id: documentRequest.id,
        disabled: !canEdit,
    });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition: prefersReducedMotion ? undefined : transition,
    };

    return (
        <li
            ref={setNodeRef}
            style={style}
            className={cn(
                'bg-card rounded-xl border shadow-sm transition-[box-shadow,border-color] duration-150 motion-reduce:transition-none',
                selected
                    ? 'border-primary ring-primary/20 ring-2'
                    : 'border-border/80',
                isDragging && 'opacity-40',
            )}
        >
            <div className="flex items-center gap-1 px-2 py-2">
                {canEdit ? (
                    <button
                        type="button"
                        className="text-muted-foreground hover:bg-muted inline-flex size-8 shrink-0 cursor-grab items-center justify-center rounded-md active:cursor-grabbing"
                        aria-label={t('Drag to reorder')}
                        {...attributes}
                        {...listeners}
                    >
                        <GripVertical aria-hidden="true" className="size-4" />
                    </button>
                ) : (
                    <span className="size-8 shrink-0" aria-hidden="true" />
                )}

                <button
                    type="button"
                    className="focus-visible:ring-ring/50 flex min-w-0 flex-1 items-center gap-2 rounded-lg px-1 py-1 text-left focus-visible:ring-[3px] focus-visible:outline-none"
                    aria-pressed={selected}
                    aria-label={documentRequest.title}
                    onClick={() => onSelect(documentRequest.id)}
                >
                    <span className="font-data text-muted-foreground w-5 shrink-0 text-xs tabular-nums">
                        {index + 1}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                        {documentRequest.title}
                    </span>
                    <Badge variant="outline" className="shrink-0">
                        {t(definition.label)}
                    </Badge>
                </button>

                {canEdit ? (
                    <ConfirmDestroyDialog
                        title={t('Delete request?')}
                        description={t(
                            'Remove “:title” from this dossier. Uploaded files for this item are kept for audit purposes.',
                            { title: documentRequest.title },
                        )}
                        confirmLabel={t('Delete')}
                        onConfirm={async () => {
                            await onDestroy(documentRequest.id);
                        }}
                        trigger={
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-8 shrink-0"
                                aria-label={t('Delete :title', {
                                    title: documentRequest.title,
                                })}
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        }
                    />
                ) : null}
            </div>

            {selected ? (
                <div className="border-border/60 border-t px-3 py-3">
                    <BuilderPreview
                        title={documentRequest.title}
                        instructions={documentRequest.instructions}
                        configuration={documentRequest.configuration}
                    />
                </div>
            ) : null}
        </li>
    );
}
