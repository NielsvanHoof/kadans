import { Form, Head, setLayoutProps } from '@inertiajs/react';
import QuestionnaireTemplateController from '@/actions/App/Http/Controllers/Questionnaires/QuestionnaireTemplateController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
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
                    className="mt-6 space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('Name')}</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder={t('KYC light')}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="category">
                                    {t('Category')}
                                </Label>
                                <Select
                                    required
                                    defaultValue="custom"
                                    name="category"
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue
                                            placeholder={t('Select a category')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent className="bg-background">
                                        {categories.map((category) => (
                                            <SelectItem
                                                key={category.value}
                                                value={category.value}
                                            >
                                                {category.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.category} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    {t('Description (optional)')}
                                </Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    rows={3}
                                    placeholder={t('When to use this pack…')}
                                />
                                <InputError message={errors.description} />
                            </div>

                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                {t('Create template')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
