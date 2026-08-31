import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ApplyTemplateOption } from '@/features/questionnaires/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Append a reusable questionnaire pack into the local builder draft.
 * Nothing is persisted until the user clicks Save.
 */
export default function QuestionnaireBuilderTemplates({
    templates,
    canEdit,
    onApply,
}: {
    templates: ApplyTemplateOption[];
    canEdit: boolean;
    onApply: (template: ApplyTemplateOption) => void;
}) {
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
        <Collapsible className="border-border/60 border-t pt-3">
            <CollapsibleTrigger
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
                    <span className="text-muted-foreground block text-xs font-normal text-pretty">
                        {t(
                            'Append a reusable pack. Existing items are kept. Save to persist.',
                        )}
                    </span>
                </span>
                <ChevronDown
                    className="size-4 shrink-0 transition-transform group-aria-expanded:rotate-180"
                    aria-hidden="true"
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-3">
                <FieldGroup className="gap-3">
                    <Field className="min-w-0">
                        <FieldLabel htmlFor="questionnaire_template_id">
                            {t('Template')}
                        </FieldLabel>
                        <Select
                            isRequired
                            placeholder={t('Select a template…')}
                            selectedKey={selectedTemplateId || undefined}
                            onSelectionChange={(key) =>
                                setSelectedTemplateId(String(key ?? ''))
                            }
                            isDisabled={!canEdit}
                            aria-label={t('Select a template…')}
                        >
                            <SelectTrigger
                                id="questionnaire_template_id"
                                className="w-full min-w-0 overflow-hidden"
                            >
                                <SelectValue>
                                    {selectedTemplate?.name}
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent className="bg-background max-w-(--anchor-width)">
                                <SelectGroup>
                                    {templates.map((template) => (
                                        <SelectItem
                                            key={template.id}
                                            id={template.id.toString()}
                                            textValue={template.name}
                                            className="items-start"
                                        >
                                            <span className="flex min-w-0 flex-col gap-0.5 text-left">
                                                <span className="truncate font-medium">
                                                    {template.name}
                                                </span>
                                                <span className="text-muted-foreground truncate text-xs">
                                                    {template.category_label} (
                                                    {template.items_count})
                                                    {template.is_system
                                                        ? ` · ${t('System')}`
                                                        : ''}
                                                </span>
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field>
                        <Button
                            type="button"
                            isDisabled={!canEdit || !selectedTemplate}
                            className="w-full"
                            size="sm"
                            onClick={() => {
                                if (selectedTemplate) {
                                    onApply(selectedTemplate);
                                }
                            }}
                        >
                            {t('Apply template')}
                        </Button>
                    </Field>
                </FieldGroup>
            </CollapsibleContent>
        </Collapsible>
    );
}
