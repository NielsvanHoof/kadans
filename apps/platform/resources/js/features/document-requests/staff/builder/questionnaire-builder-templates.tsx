import { Form } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import DocumentRequestController from '@/actions/App/Http/Controllers/Dossiers/DocumentRequestController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ApplyTemplateOption } from '@/features/questionnaires/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { inlineDossierActionOptions } from '@/lib/inline-dossier-action-options';

/**
 * Append a reusable questionnaire pack onto the current dossier form.
 */
export default function QuestionnaireBuilderTemplates({
    dossierId,
    templates,
}: {
    dossierId: number;
    templates: ApplyTemplateOption[];
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    // Controlled so the closed trigger can show only the template name.
    const [selectedTemplateId, setSelectedTemplateId] = useState<string>('');

    if (templates.length === 0) {
        return null;
    }

    const selectedTemplate = templates.find(
        (template) => template.id.toString() === selectedTemplateId,
    );

    return (
        <Collapsible className="border-t border-border/60 pt-3">
            <CollapsibleTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    // Allow the helper line to wrap in the narrow palette column.
                    className="group h-auto w-full justify-between gap-2 px-0 py-1 text-left whitespace-normal"
                >
                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold">
                            {t('Templates')}
                        </span>
                        <span className="block text-xs font-normal text-pretty text-muted-foreground">
                            {t(
                                'Append a reusable pack. Existing items are kept.',
                            )}
                        </span>
                    </span>
                    <ChevronDown
                        className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180"
                        aria-hidden="true"
                    />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-3">
                <Form
                    {...DocumentRequestController.applyTemplate.form({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                    })}
                    options={inlineDossierActionOptions}
                    className="space-y-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid min-w-0 gap-2">
                                <Label htmlFor="questionnaire_template_id">
                                    {t('Template')}
                                </Label>
                                <Select
                                    required
                                    name="questionnaire_template_id"
                                    value={selectedTemplateId || undefined}
                                    onValueChange={setSelectedTemplateId}
                                >
                                    <SelectTrigger
                                        id="questionnaire_template_id"
                                        className="w-full min-w-0 overflow-hidden"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'Select a template…',
                                            )}
                                        >
                                            {selectedTemplate?.name}
                                        </SelectValue>
                                    </SelectTrigger>
                                    {/* Match trigger width; flip upward in the narrow palette. */}
                                    <SelectContent
                                        align="start"
                                        className="w-[var(--radix-select-trigger-width)] max-w-[var(--radix-select-trigger-width)] bg-background"
                                    >
                                        {templates.map((template) => (
                                            <SelectItem
                                                key={template.id}
                                                value={template.id.toString()}
                                                className="items-start"
                                            >
                                                <span className="flex min-w-0 flex-col gap-0.5 text-left">
                                                    <span className="truncate font-medium">
                                                        {template.name}
                                                    </span>
                                                    <span className="truncate text-xs text-muted-foreground">
                                                        {
                                                            template.category_label
                                                        }{' '}
                                                        ({template.items_count})
                                                        {template.is_system
                                                            ? ` · ${t('System')}`
                                                            : ''}
                                                    </span>
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={errors.questionnaire_template_id}
                                />
                            </div>
                            <Button
                                disabled={processing || !selectedTemplateId}
                                className="w-full"
                                size="sm"
                            >
                                {t('Apply template')}
                            </Button>
                        </>
                    )}
                </Form>
            </CollapsibleContent>
        </Collapsible>
    );
}
