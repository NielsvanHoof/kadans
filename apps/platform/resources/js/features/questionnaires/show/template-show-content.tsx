import { Form } from '@inertiajs/react';
import QuestionnaireTemplateController from '@/actions/App/Questionnaires/Http/Controllers/QuestionnaireTemplateController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import Heading from '@/components/primitives/heading';
import InputError from '@/components/primitives/input-error';
import { Badge } from '@/components/ui/badge';
import { Button, LinkButton } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import TemplateItemEditor from '@/features/questionnaires/show/template-item-editor';
import type {
    TemplateCategoryOption,
    TemplateDetail,
} from '@/features/questionnaires/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as templateIndex } from '@/routes/workspaces/templates';

/**
 * Template detail body: header actions, editable details, and item editor.
 */
export default function TemplateShowContent({
    template,
    categories,
    canManage,
    canCopy,
}: {
    template: TemplateDetail;
    categories: TemplateCategoryOption[];
    canManage: boolean;
    canCopy: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Heading level={1} title={template.name} />
                        <Badge variant="secondary">
                            {template.category_label}
                        </Badge>
                        {template.is_system && (
                            <Badge variant="outline">{t('System')}</Badge>
                        )}
                    </div>
                    {template.description && (
                        <p className="text-muted-foreground mt-1 text-sm">
                            {template.description}
                        </p>
                    )}
                </div>

                <div className="flex flex-wrap gap-2">
                    {canCopy && (
                        <Form
                            {...QuestionnaireTemplateController.copy.form({
                                tenant: currentWorkspace,
                                template: template.id,
                            })}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    isDisabled={processing}
                                >
                                    {t('Copy to my firm')}
                                </Button>
                            )}
                        </Form>
                    )}
                    {canManage && (
                        <ConfirmDestroyDialog
                            title={t('Delete template?')}
                            description={t(
                                'This permanently deletes the template and its items. Existing dossiers that used it are not changed.',
                            )}
                            confirmLabel={t('Delete')}
                            form={QuestionnaireTemplateController.destroy.form({
                                tenant: currentWorkspace,
                                template: template.id,
                            })}
                            trigger={
                                <Button type="button" variant="destructive">
                                    {t('Delete')}
                                </Button>
                            }
                        />
                    )}
                    <LinkButton
                        variant="outline"
                        href={templateIndex(currentWorkspace).url}
                    >
                        {t('Back')}
                    </LinkButton>
                </div>
            </div>

            {canManage && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Template details')}</CardTitle>
                        <CardDescription>
                            {t('Update the name, category, and description.')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...QuestionnaireTemplateController.update.form({
                                tenant: currentWorkspace,
                                template: template.id,
                            })}
                        >
                            {({ errors, processing }) => (
                                <FieldGroup className="gap-4">
                                    <Field data-invalid={Boolean(errors.name)}>
                                        <FieldLabel htmlFor="name">
                                            {t('Name')}
                                        </FieldLabel>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            defaultValue={template.name}
                                            aria-invalid={Boolean(errors.name)}
                                        />
                                        <InputError message={errors.name} />
                                    </Field>
                                    <Field
                                        data-invalid={Boolean(errors.category)}
                                    >
                                        <FieldLabel htmlFor="category">
                                            {t('Category')}
                                        </FieldLabel>
                                        {/* React Aria Select ignores
                                            FieldLabel+htmlFor (LabelContext
                                            is nulled when htmlFor is set). */}
                                        <Select
                                            isRequired
                                            defaultValue={template.category}
                                            name="category"
                                            aria-label={t('Category')}
                                        >
                                            <SelectTrigger
                                                id="category"
                                                className="w-full"
                                                aria-invalid={Boolean(
                                                    errors.category,
                                                )}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent className="bg-background">
                                                <SelectGroup>
                                                    {categories.map(
                                                        (category) => (
                                                            <SelectItem
                                                                key={
                                                                    category.value
                                                                }
                                                                id={
                                                                    category.value
                                                                }
                                                            >
                                                                {category.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.category} />
                                    </Field>
                                    <Field
                                        data-invalid={Boolean(
                                            errors.description,
                                        )}
                                    >
                                        <FieldLabel htmlFor="description">
                                            {t('Description')}
                                        </FieldLabel>
                                        <Textarea
                                            id="description"
                                            name="description"
                                            rows={3}
                                            defaultValue={
                                                template.description ?? ''
                                            }
                                            aria-invalid={Boolean(
                                                errors.description,
                                            )}
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </Field>
                                    <Field orientation="horizontal">
                                        <Button
                                            type="submit"
                                            isDisabled={processing}
                                        >
                                            {t('Save details')}
                                        </Button>
                                    </Field>
                                </FieldGroup>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>{t('Questionnaire items')}</CardTitle>
                    <CardDescription>
                        {template.is_system
                            ? t(
                                  'System templates are read-only. Copy to customise.',
                              )
                            : t(
                                  'Add, edit, or remove items. Drag the grip to reorder.',
                              )}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <TemplateItemEditor
                        templateId={template.id}
                        items={template.items}
                        canManage={canManage}
                    />
                </CardContent>
            </Card>
        </div>
    );
}
