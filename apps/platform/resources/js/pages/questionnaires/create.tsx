import { Form, Head, setLayoutProps } from '@inertiajs/react';
import QuestionnaireTemplateController from '@/actions/App/Questionnaires/Http/Controllers/QuestionnaireTemplateController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { TemplateCategoryOption } from '@/features/questionnaires/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    create as createTemplate,
    index as templateIndex,
} from '@/routes/workspaces/templates';

/**
 * Create a blank firm-owned questionnaire template.
 */
export default function CreateTemplate({
    categories,
}: {
    categories: TemplateCategoryOption[];
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Templates'),
                href: templateIndex(currentWorkspace),
            },
            {
                title: t('New template'),
                href: createTemplate(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('New template')} />

            <div className="mx-auto w-full max-w-xl p-4 md:p-8">
                <Heading
                    level={1}
                    title={t('New template')}
                    description={t(
                        'Create a reusable questionnaire for your firm.',
                    )}
                />

                <Form
                    {...QuestionnaireTemplateController.store.form(
                        currentWorkspace,
                    )}
                >
                    {({ errors, processing }) => (
                        <FieldGroup className="mt-6 gap-6">
                            <Field data-invalid={Boolean(errors.name)}>
                                <FieldLabel htmlFor="name">
                                    {t('Name')}
                                </FieldLabel>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder={t('KYC light')}
                                    aria-invalid={Boolean(errors.name)}
                                />
                                <InputError message={errors.name} />
                            </Field>

                            <Field data-invalid={Boolean(errors.category)}>
                                <FieldLabel htmlFor="category">
                                    {t('Category')}
                                </FieldLabel>
                                <Select
                                    required
                                    defaultValue="custom"
                                    name="category"
                                >
                                    <SelectTrigger
                                        id="category"
                                        className="w-full"
                                        aria-invalid={Boolean(errors.category)}
                                    >
                                        <SelectValue
                                            placeholder={t('Select a category')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent className="bg-background">
                                        <SelectGroup>
                                            {categories.map((category) => (
                                                <SelectItem
                                                    key={category.value}
                                                    value={category.value}
                                                >
                                                    {category.label}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.category} />
                            </Field>

                            <Field data-invalid={Boolean(errors.description)}>
                                <FieldLabel htmlFor="description">
                                    {t('Description (optional)')}
                                </FieldLabel>
                                <Textarea
                                    id="description"
                                    name="description"
                                    rows={3}
                                    placeholder={t('When to use this pack…')}
                                    aria-invalid={Boolean(errors.description)}
                                />
                                <InputError message={errors.description} />
                            </Field>

                            <Field orientation="horizontal">
                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('Create template')}
                                </Button>
                            </Field>
                        </FieldGroup>
                    )}
                </Form>
            </div>
        </>
    );
}
