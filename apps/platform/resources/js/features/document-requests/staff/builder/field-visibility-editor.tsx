import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    DocumentRequest,
    QuestionnaireItemConfiguration,
    QuestionnaireOption,
} from '@/features/document-requests/types';
import {
    BOOLEAN_NO_OPTION_ID,
    BOOLEAN_YES_OPTION_ID,
    canDriveVisibility,
    isOrderedBefore,
} from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

type Visibility = NonNullable<QuestionnaireItemConfiguration['visibility']>;

/** Sentinel select value meaning "no condition", since Select cannot hold null. */
const NO_SOURCE = '__none__';

/**
 * Fields whose answer may drive `documentRequest`'s visibility.
 *
 * Restricted to earlier fields, which is what prevents a rule from depending on
 * an answer that only becomes available later — or on itself. The same restriction
 * is enforced server-side; this only keeps the impossible choices out of the UI.
 */
export function selectVisibilitySourceFields(
    documentRequest: DocumentRequest,
    siblings: DocumentRequest[],
): DocumentRequest[] {
    return siblings.filter(
        (sibling) =>
            sibling.id !== documentRequest.id &&
            isOrderedBefore(sibling, documentRequest) &&
            canDriveVisibility(sibling.type),
    );
}

/** Picks the one earlier field and the answers that reveal this field. */
export function FieldVisibilityEditor({
    documentRequestId,
    sourceFields,
    visibility,
    canEdit,
    onChange,
}: {
    documentRequestId: number;
    sourceFields: DocumentRequest[];
    visibility: Visibility | undefined;
    canEdit: boolean;
    onChange: (visibility: Visibility | undefined) => void;
}) {
    const { t } = useTranslation();
    const sourceField =
        sourceFields.find(
            (field) => field.field_key === visibility?.source_field_key,
        ) ?? null;
    const matchableOptions = resolveMatchableOptions(sourceField, t);

    const changeSource = (fieldKey: string) => {
        if (fieldKey === NO_SOURCE) {
            onChange(undefined);

            return;
        }

        const source = sourceFields.find(
            (field) => field.field_key === fieldKey,
        );

        if (!source) {
            return;
        }

        // Preselect one answer so the rule is complete the moment it is created.
        const [firstOption] = resolveMatchableOptions(source, t);

        if (!firstOption) {
            return;
        }

        onChange({
            source_field_key: fieldKey,
            option_ids: [firstOption.id],
        });
    };

    const toggleOption = (optionId: string, checked: boolean) => {
        if (!visibility) {
            return;
        }

        const optionIds = new Set(visibility.option_ids);

        if (checked) {
            optionIds.add(optionId);
        } else {
            optionIds.delete(optionId);
        }

        // A rule matching nothing could never show the field, so keep the last answer.
        if (optionIds.size === 0) {
            return;
        }

        onChange({
            source_field_key: visibility.source_field_key,
            option_ids: [...optionIds],
        });
    };

    return (
        <FieldGroup className="gap-3">
            <div>
                <FieldLabel>{t('Show this field when…')}</FieldLabel>
                <FieldDescription className="text-xs">
                    {t(
                        'Optional. Only earlier yes/no or choice fields can be used.',
                    )}
                </FieldDescription>
            </div>

            <Field data-disabled={!canEdit}>
                <FieldLabel
                    htmlFor={`builder-visibility-source-${documentRequestId}`}
                >
                    {t('Depends on')}
                </FieldLabel>
                <Select
                    selectedKey={visibility?.source_field_key ?? NO_SOURCE}
                    isDisabled={!canEdit || sourceFields.length === 0}
                    onSelectionChange={(key) => changeSource(String(key))}
                    placeholder={
                        sourceFields.length === 0
                            ? t('No earlier choice fields yet')
                            : t('Always show')
                    }
                    aria-label={
                        sourceFields.length === 0
                            ? t('No earlier choice fields yet')
                            : t('Always show')
                    }
                >
                    <SelectTrigger
                        id={`builder-visibility-source-${documentRequestId}`}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent className="bg-background">
                        <SelectGroup>
                            <SelectItem id={NO_SOURCE}>
                                {t('Always show')}
                            </SelectItem>
                            {sourceFields.map((field) => (
                                <SelectItem
                                    key={field.field_key}
                                    id={field.field_key}
                                >
                                    {field.title}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </Field>

            {visibility && matchableOptions.length > 0 ? (
                <FieldGroup className="gap-2">
                    <FieldLabel>{t('Matching answers')}</FieldLabel>
                    {matchableOptions.map((option) => {
                        const optionElementId = `builder-visibility-option-${documentRequestId}-${option.id}`;

                        return (
                            <Field
                                key={option.id}
                                orientation="horizontal"
                                data-disabled={!canEdit}
                                className="items-center"
                            >
                                <Checkbox
                                    id={optionElementId}
                                    isSelected={visibility.option_ids.includes(
                                        option.id,
                                    )}
                                    isDisabled={!canEdit}
                                    onChange={(checked) =>
                                        toggleOption(
                                            option.id,
                                            checked === true,
                                        )
                                    }
                                />
                                <FieldLabel
                                    htmlFor={optionElementId}
                                    className="font-normal"
                                >
                                    {option.label}
                                </FieldLabel>
                            </Field>
                        );
                    })}
                </FieldGroup>
            ) : null}
        </FieldGroup>
    );
}

/**
 * The answers a rule can match on. Yes/no fields have no stored options, so they
 * use the two fixed boolean ids the backend compares against.
 */
function resolveMatchableOptions(
    sourceField: DocumentRequest | null,
    t: (key: string) => string,
): QuestionnaireOption[] {
    if (sourceField === null) {
        return [];
    }

    if (sourceField.type === 'boolean') {
        return [
            { id: BOOLEAN_YES_OPTION_ID, label: t('Yes') },
            { id: BOOLEAN_NO_OPTION_ID, label: t('No') },
        ];
    }

    return sourceField.configuration?.options ?? [];
}
