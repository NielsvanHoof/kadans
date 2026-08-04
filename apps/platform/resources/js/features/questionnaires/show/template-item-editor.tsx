import { Form, router, useHttp } from '@inertiajs/react';
import { Sparkles, Trash2 } from 'lucide-react';
import { useCallback, useState } from 'react';
import QuestionnaireChecklistDraftController from '@/actions/App/Questionnaires/Http/Controllers/QuestionnaireChecklistDraftController';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { getQuestionnaireItemTypeDefinition } from '@/features/document-requests/item-types/definitions';
import QuestionnaireItemTypeSelect from '@/features/document-requests/questionnaire-item-type-select';
import type { QuestionnaireItemType } from '@/features/document-requests/types';
import type {
    DraftedChecklistItem,
    TemplateItem,
} from '@/features/questionnaires/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

type NewItemDefaults = {
    type: QuestionnaireItemType;
    title: string;
    instructions: string;
};

const blankNewItem: NewItemDefaults = {
    type: 'file',
    title: '',
    instructions: '',
};

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

    const [draftDescription, setDraftDescription] = useState('');
    const [suggestions, setSuggestions] = useState<DraftedChecklistItem[]>([]);
    const [addingSuggestion, setAddingSuggestion] =
        useState<DraftedChecklistItem | null>(null);
    const [addingAll, setAddingAll] = useState(false);
    const {
        post: postDraft,
        setData: setDraftData,
        processing: drafting,
    } = useHttp<{ description: string }, { items: DraftedChecklistItem[] }>({
        description: '',
    });

    async function generateSuggestions() {
        setDraftData('description', draftDescription);

        const response = await postDraft(
            QuestionnaireChecklistDraftController.store.url({
                tenant: currentWorkspace,
                template: templateId,
            }),
        );

        setSuggestions(response.items);
    }

    function addSuggestion(item: DraftedChecklistItem): Promise<void> {
        return new Promise((resolve) => {
            router.post(
                QuestionnaireTemplateItemController.store.url({
                    tenant: currentWorkspace,
                    template: templateId,
                }),
                {
                    type: item.type,
                    title: item.title,
                    instructions: item.instructions,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setSuggestions((current) =>
                            current.filter((s) => s !== item),
                        );
                    },
                    onFinish: () => resolve(),
                },
            );
        });
    }

    async function applySuggestion(item: DraftedChecklistItem) {
        setAddingSuggestion(item);
        await addSuggestion(item);
        setAddingSuggestion(null);
    }

    async function applyAllSuggestions() {
        setAddingAll(true);

        for (const item of suggestions) {
            await addSuggestion(item);
        }

        setAddingAll(false);
    }

    function dismissSuggestion(item: DraftedChecklistItem) {
        setSuggestions((current) => current.filter((s) => s !== item));
    }

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
                                                    isDisabled={processing}
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
                <div className="space-y-3 rounded-md border border-dashed p-4">
                    <Field>
                        <FieldLabel htmlFor="checklist-draft-description">
                            {t('Suggest items with AI')}
                        </FieldLabel>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="checklist-draft-description"
                                value={draftDescription}
                                onChange={(event) =>
                                    setDraftDescription(event.target.value)
                                }
                                placeholder={t(
                                    'e.g. Onboarding a new freelance client for their first tax return',
                                )}
                                className="flex-1"
                            />
                            <Button
                                type="button"
                                variant="secondary"
                                isDisabled={
                                    drafting || draftDescription.trim() === ''
                                }
                                onClick={generateSuggestions}
                            >
                                {drafting ? (
                                    <Spinner />
                                ) : (
                                    <Sparkles aria-hidden="true" />
                                )}
                                {drafting
                                    ? t('Suggesting…')
                                    : t('Suggest items')}
                            </Button>
                        </div>
                    </Field>

                    {suggestions.length > 0 && (
                        <>
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-sm text-muted-foreground">
                                    {t(':count suggestions', {
                                        count: suggestions.length,
                                    })}
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    isDisabled={
                                        addingSuggestion !== null || addingAll
                                    }
                                    onClick={applyAllSuggestions}
                                >
                                    {addingAll && <Spinner />}
                                    {t('Add all')}
                                </Button>
                            </div>
                            <ul className="space-y-2">
                                {suggestions.map((item) => (
                                    <li
                                        key={`${item.type}:${item.title}:${item.instructions}`}
                                        className="flex items-start justify-between gap-3 rounded-md border bg-muted/20 p-3"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                <Badge variant="outline">
                                                    {t(
                                                        getQuestionnaireItemTypeDefinition(
                                                            item.type,
                                                        ).label,
                                                    )}
                                                </Badge>
                                                <p className="truncate font-medium">
                                                    {item.title}
                                                </p>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {item.instructions}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                isDisabled={
                                                    addingSuggestion !== null ||
                                                    addingAll
                                                }
                                                onClick={() =>
                                                    applySuggestion(item)
                                                }
                                            >
                                                {addingSuggestion === item && (
                                                    <Spinner />
                                                )}
                                                {t('Use')}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                isDisabled={
                                                    addingSuggestion !== null ||
                                                    addingAll
                                                }
                                                onClick={() =>
                                                    dismissSuggestion(item)
                                                }
                                            >
                                                {t('Dismiss')}
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
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
                                defaults={blankNewItem}
                                errors={errors}
                            />
                            <Field orientation="horizontal">
                                <Button type="submit" isDisabled={processing}>
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
