import { Form, useHttp } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useCallback } from 'react';
import QuestionnaireTemplateItemController from '@/actions/App/Questionnaires/Http/Controllers/QuestionnaireTemplateItemController';
import SortableList from '@/components/data/sortable/sortable-list';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import EmptyState from '@/components/primitives/empty-state';
import InputError from '@/components/primitives/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldTitle,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import QuestionnaireItemTypeSelect from '@/features/document-requests/questionnaire-item-type-select';
import type { QuestionnaireItemType } from '@/features/document-requests/types';
import type { TemplateItem } from '@/features/questionnaires/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Add, edit, reorder, and delete items on a firm-owned template.
 */
export default function TemplateItemEditor({
    templateId,
    items,
    canManage,
}: {
    templateId: number;
    items: TemplateItem[];
    canManage: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { post, setData } = useHttp({
        item_ids: [] as number[],
    });

    const persistOrder = useCallback(
        async (orderedIds: number[]): Promise<boolean> => {
            setData('item_ids', orderedIds);

            let hasValidationErrors = false;

            try {
                await post(
                    QuestionnaireTemplateItemController.reorder.url({
                        tenant: currentWorkspace,
                        template: templateId,
                    }),
                    {
                        onError: () => {
                            hasValidationErrors = true;
                        },
                    },
                );

                return !hasValidationErrors;
            } catch {
                return false;
            }
        },
        [currentWorkspace, post, setData, templateId],
    );

    return (
        <div className="space-y-6">
            {items.length === 0 ? (
                <EmptyState
                    title={t('No items yet. Add your first question below.')}
                    bordered
                />
            ) : (
                <SortableList
                    items={items}
                    enabled={canManage}
                    onReorder={persistOrder}
                    className="divide-y rounded-md border"
                    renderItem={(item, { DragHandle }) => (
                        <div className="space-y-3 px-4 py-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-1">
                                    {canManage && <DragHandle />}
                                    <Badge variant="outline">
                                        {t(
                                            getQuestionnaireItemTypeDefinition(
                                                item.type,
                                            ).label,
                                        )}
                                    </Badge>
                                </div>
                                {canManage && (
                                    <ConfirmDestroyDialog
                                        title={t('Delete item?')}
                                        description={t(
                                            'Remove “:title” from this template. This cannot be undone.',
                                            { title: item.title },
                                        )}
                                        confirmLabel={t('Delete')}
                                        form={QuestionnaireTemplateItemController.destroy.form(
                                            {
                                                tenant: currentWorkspace,
                                                template: templateId,
                                                item: item.id,
                                            },
                                        )}
                                        trigger={
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                aria-label={t('Delete :title', {
                                                    title: item.title,
                                                })}
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </Button>
                                        }
                                    />
                                )}
                            </div>

                            {canManage ? (
                                <Form
                                    {...QuestionnaireTemplateItemController.update.form(
                                        {
                                            tenant: currentWorkspace,
                                            template: templateId,
                                            item: item.id,
                                        },
                                    )}
                                >
                                    {({ errors, processing }) => (
                                        <FieldGroup className="gap-3">
                                            <ItemFields
                                                idPrefix={`item-${item.id}`}
                                                defaults={item}
                                                errors={errors}
                                            />
                                            <Field orientation="horizontal">
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {t('Save item')}
                                                </Button>
                                            </Field>
                                        </FieldGroup>
                                    )}
                                </Form>
                            ) : (
                                <div>
                                    <p className="font-medium">{item.title}</p>
                                    {item.instructions && (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {item.instructions}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                />
            )}

            {canManage && (
                <Form
                    {...QuestionnaireTemplateItemController.store.form({
                        tenant: currentWorkspace,
                        template: templateId,
                    })}
                    resetOnSuccess
                    className="rounded-md border p-4"
                >
                    {({ errors, processing }) => (
                        <FieldGroup className="gap-3">
                            <Field>
                                <FieldTitle>{t('Add item')}</FieldTitle>
                            </Field>
                            <ItemFields
                                idPrefix="new-item"
                                defaults={{
                                    type: 'file',
                                    title: '',
                                    instructions: '',
                                }}
                                errors={errors}
                            />
                            <Field orientation="horizontal">
                                <Button type="submit" disabled={processing}>
                                    {t('Add item')}
                                </Button>
                            </Field>
                        </FieldGroup>
                    )}
                </Form>
            )}
        </div>
    );
}

function ItemFields({
    idPrefix,
    defaults,
    errors,
}: {
    idPrefix: string;
    defaults: {
        type: QuestionnaireItemType;
        title: string;
        instructions: string | null;
    };
    errors: Partial<Record<string, string>>;
}) {
    const { t } = useTranslation();

    return (
        <>
            <QuestionnaireItemTypeSelect
                id={`${idPrefix}-type`}
                defaultValue={defaults.type}
                error={errors.type}
            />
            <Field data-invalid={Boolean(errors.title)}>
                <FieldLabel htmlFor={`${idPrefix}-title`}>
                    {t('Title')}
                </FieldLabel>
                <Input
                    id={`${idPrefix}-title`}
                    name="title"
                    required
                    defaultValue={defaults.title}
                    placeholder={t('Copy of identity document')}
                    aria-invalid={Boolean(errors.title)}
                />
                <InputError message={errors.title} />
            </Field>
            <Field data-invalid={Boolean(errors.instructions)}>
                <FieldLabel htmlFor={`${idPrefix}-instructions`}>
                    {t('Instructions (optional)')}
                </FieldLabel>
                <Textarea
                    id={`${idPrefix}-instructions`}
                    name="instructions"
                    rows={2}
                    defaultValue={defaults.instructions ?? ''}
                    aria-invalid={Boolean(errors.instructions)}
                />
                <InputError message={errors.instructions} />
            </Field>
        </>
    );
}
