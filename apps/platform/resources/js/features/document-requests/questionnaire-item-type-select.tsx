import InputError from '@/components/primitives/input-error';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getQuestionnaireItemTypeDefinitions } from '@/features/document-requests/item-types/definitions';
import type { QuestionnaireItemType } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Shared type selector keeps values and labels consistent in every editor.
 */
export default function QuestionnaireItemTypeSelect({
    defaultValue,
    error,
    id,
}: {
    defaultValue: QuestionnaireItemType;
    error?: string;
    id?: string;
}) {
    const { t } = useTranslation();
    const typeDefinitions = getQuestionnaireItemTypeDefinitions(t);

    return (
        <Field data-invalid={Boolean(error)}>
            <FieldLabel htmlFor={id}>{t('Type')}</FieldLabel>
            {/*
              React Aria Select ignores FieldLabel+htmlFor (LabelContext is
              nulled when htmlFor is set). Pass aria-label so the trigger
              stays labelled without nesting a RAC Label inside Select.
            */}
            <Select
                isRequired
                defaultValue={defaultValue}
                name="type"
                aria-label={t('Type')}
            >
                <SelectTrigger
                    id={id}
                    className="w-full"
                    aria-invalid={Boolean(error)}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent className="bg-background">
                    <SelectGroup>
                        {typeDefinitions.map((type) => (
                            <SelectItem key={type.value} id={type.value}>
                                {type.label}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
            <InputError message={error} />
        </Field>
    );
}
