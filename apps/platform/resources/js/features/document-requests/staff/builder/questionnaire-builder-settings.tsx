import InputError from '@/components/primitives/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
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
import { getQuestionnaireItemTypeDefinitions } from '@/features/document-requests/item-types/definitions';
import {
    buildDefaultOptions,
    FieldOptionsEditor,
} from '@/features/document-requests/staff/builder/field-options-editor';
import {
    FieldVisibilityEditor,
    selectVisibilitySourceFields,
} from '@/features/document-requests/staff/builder/field-visibility-editor';
import type { FieldSettingsSaveInput } from '@/features/document-requests/staff/builder/use-field-settings-form';
import { useFieldSettingsForm } from '@/features/document-requests/staff/builder/use-field-settings-form';
import type { BuilderSaveStatus } from '@/features/document-requests/staff/builder/use-questionnaire-builder-actions';
import type {
    DocumentRequest,
    QuestionnaireItemType,
} from '@/features/document-requests/types';
import { storesAnswerOptionIds } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Autosaving field settings for the selected canvas component.
 * Type saves immediately. Title, instructions, and configuration save on blur / change.
 *
 * All draft state and validation live in useFieldSettingsForm, so this component
 * only decides what to show.
 */
export default function QuestionnaireBuilderSettings({
    documentRequest,
    siblings,
    canEdit,
    saveStatus,
    onSave,
}: {
    documentRequest: DocumentRequest | null;
    /** Earlier + later fields — used to pick visibility sources. */
    siblings: DocumentRequest[];
    canEdit: boolean;
    saveStatus: BuilderSaveStatus;
    onSave: (input: FieldSettingsSaveInput) => Promise<boolean>;
}) {
    const { t } = useTranslation();
    const form = useFieldSettingsForm(documentRequest, onSave, canEdit);

    if (!documentRequest) {
        return <EmptySettingsPanel />;
    }

    const definitions = getQuestionnaireItemTypeDefinitions(t);
    const selectedDefinition =
        definitions.find((definition) => definition.value === form.type) ??
        null;

    return (
        <aside
            className="flex flex-col gap-4 rounded-2xl border border-border/70 bg-card p-4"
            aria-label={t('Field settings')}
        >
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h3 className="text-sm font-semibold">
                        {t('Field settings')}
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {t(
                            'Edits update your draft. Save the form to persist.',
                        )}
                    </p>
                </div>
                <p
                    className="font-data text-xs text-muted-foreground"
                    aria-live="polite"
                >
                    {describeSaveState(saveStatus, form.isDirty, t)}
                </p>
            </div>

            <FieldGroup className="gap-4">
                <Field data-disabled={!canEdit}>
                    <FieldLabel htmlFor={`builder-type-${documentRequest.id}`}>
                        {t('Type')}
                    </FieldLabel>
                    <Select
                        selectedKey={form.type}
                        isDisabled={!canEdit}
                        onSelectionChange={(value) =>
                            form.changeType(
                                value as QuestionnaireItemType,
                                buildDefaultOptions(t),
                            )
                        }
                    >
                        <SelectTrigger
                            id={`builder-type-${documentRequest.id}`}
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent className="bg-background">
                            <SelectGroup>
                                {definitions.map((definition) => (
                                    <SelectItem
                                        key={definition.value}
                                        id={definition.value}
                                    >
                                        {definition.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    {selectedDefinition ? (
                        <FieldDescription className="text-xs text-pretty">
                            {selectedDefinition.description}
                        </FieldDescription>
                    ) : null}
                </Field>

                <Field
                    data-disabled={!canEdit}
                    data-invalid={Boolean(form.error)}
                >
                    <FieldLabel htmlFor={`builder-title-${documentRequest.id}`}>
                        {t('Label')}
                    </FieldLabel>
                    <Input
                        id={`builder-title-${documentRequest.id}`}
                        value={form.title}
                        disabled={!canEdit}
                        autoComplete="off"
                        onChange={(event) => form.setTitle(event.target.value)}
                        onBlur={form.saveDraft}
                        aria-invalid={Boolean(form.error)}
                    />
                    <InputError message={form.error ?? undefined} />
                </Field>

                <Field data-disabled={!canEdit}>
                    <FieldLabel
                        htmlFor={`builder-instructions-${documentRequest.id}`}
                    >
                        {t('Instructions')}
                    </FieldLabel>
                    <Textarea
                        id={`builder-instructions-${documentRequest.id}`}
                        rows={4}
                        value={form.instructions}
                        disabled={!canEdit}
                        onChange={(event) =>
                            form.setInstructions(event.target.value)
                        }
                        onBlur={form.saveDraft}
                        placeholder={t(
                            'Optional help text shown to the client…',
                        )}
                    />
                </Field>

                <Field
                    orientation="horizontal"
                    data-disabled={!canEdit}
                    className="items-center"
                >
                    <Checkbox
                        id={`builder-required-${documentRequest.id}`}
                        isSelected={form.configuration.is_required !== false}
                        isDisabled={!canEdit}
                        onChange={(checked) =>
                            form.updateConfiguration(
                                {
                                    ...form.configuration,
                                    is_required: checked !== false,
                                },
                                true,
                            )
                        }
                    />
                    <FieldLabel
                        htmlFor={`builder-required-${documentRequest.id}`}
                        className="font-normal"
                    >
                        {t('Required')}
                    </FieldLabel>
                </Field>

                {storesAnswerOptionIds(form.type) ? (
                    <FieldOptionsEditor
                        options={
                            form.configuration.options ?? buildDefaultOptions(t)
                        }
                        canEdit={canEdit}
                        onChange={(options) =>
                            form.updateConfiguration({
                                ...form.configuration,
                                options,
                            })
                        }
                        onSaveDraft={form.saveDraft}
                    />
                ) : null}

                <FieldVisibilityEditor
                    documentRequestId={documentRequest.id}
                    sourceFields={selectVisibilitySourceFields(
                        documentRequest,
                        siblings,
                    )}
                    visibility={form.configuration.visibility}
                    canEdit={canEdit}
                    onChange={(visibility) => {
                        const { visibility: _removed, ...rest } =
                            form.configuration;

                        form.updateConfiguration(
                            visibility ? { ...rest, visibility } : rest,
                            true,
                        );
                    }}
                />
            </FieldGroup>
        </aside>
    );
}

function EmptySettingsPanel() {
    const { t } = useTranslation();

    return (
        <aside
            className="rounded-2xl border border-dashed border-border/70 bg-muted/20 p-4"
            aria-label={t('Field settings')}
        >
            <h3 className="text-sm font-semibold">{t('Field settings')}</h3>
            <p className="mt-1 text-xs text-muted-foreground">
                {t('Select a field on the canvas to edit its settings.')}
            </p>
        </aside>
    );
}

/**
 * Autosave status shown beside the heading. An unsaved draft outranks the last
 * result, so a pending edit is never reported as saved.
 */
function describeSaveState(
    saveStatus: BuilderSaveStatus,
    isDirty: boolean,
    t: (key: string) => string,
): string | null {
    if (saveStatus === 'saving') {
        return t('Saving…');
    }

    if (isDirty) {
        return t('Unsaved changes');
    }

    if (saveStatus === 'saved') {
        return t('Saved');
    }

    if (saveStatus === 'error') {
        return t('Could not save. Check the fields and try again.');
    }

    return null;
}
