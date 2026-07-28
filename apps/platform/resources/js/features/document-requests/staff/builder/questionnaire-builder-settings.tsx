import { useEffect, useMemo, useState } from 'react';
import InputError from '@/components/input-error';
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
import type { BuilderSaveStatus } from '@/features/document-requests/staff/builder/use-questionnaire-builder-actions';
import type {
    DocumentRequest,
    QuestionnaireItemType,
} from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

type SettingsSaveInput = {
    type: QuestionnaireItemType;
    title: string;
    instructions: string | null;
};

/**
 * Autosaving field settings for the selected canvas component.
 * Type saves immediately. Title and instructions save on blur.
 */
export default function QuestionnaireBuilderSettings({
    documentRequest,
    canEdit,
    saveStatus,
    onSave,
}: {
    documentRequest: DocumentRequest | null;
    canEdit: boolean;
    saveStatus: BuilderSaveStatus;
    onSave: (input: SettingsSaveInput) => Promise<boolean>;
}) {
    const { t } = useTranslation();
    const definitions = getQuestionnaireItemTypeDefinitions(t);
    const [title, setTitle] = useState(documentRequest?.title ?? '');
    const [instructions, setInstructions] = useState(
        documentRequest?.instructions ?? '',
    );
    const [type, setType] = useState<QuestionnaireItemType>(
        documentRequest?.type ?? 'file',
    );
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setTitle(documentRequest?.title ?? '');
        setInstructions(documentRequest?.instructions ?? '');
        setType(documentRequest?.type ?? 'file');
        setError(null);
    }, [documentRequest]);

    const isDirty = useMemo(() => {
        if (!documentRequest) {
            return false;
        }

        const savedTitle = documentRequest.title.trim();
        const savedInstructions = documentRequest.instructions?.trim() ?? '';

        return (
            title.trim() !== savedTitle ||
            instructions.trim() !== savedInstructions
        );
    }, [documentRequest, title, instructions]);

    useEffect(() => {
        if (!isDirty) {
            return;
        }

        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', handleBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
        };
    }, [isDirty]);

    if (!documentRequest) {
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

    const selectedDefinition =
        definitions.find((definition) => definition.value === type) ?? null;

    const persist = async (next: SettingsSaveInput) => {
        if (!canEdit) {
            return;
        }

        const trimmedTitle = next.title.trim();

        if (trimmedTitle === '') {
            setError(t('Enter a field label.'));

            return;
        }

        setError(null);
        const ok = await onSave({
            ...next,
            title: trimmedTitle,
            instructions: next.instructions?.trim() || null,
        });

        if (!ok) {
            setError(t('Could not save. Check the fields and try again.'));
        }
    };

    const saveLabel =
        saveStatus === 'saving'
            ? t('Saving…')
            : isDirty
              ? t('Unsaved changes')
              : saveStatus === 'saved'
                ? t('Saved')
                : saveStatus === 'error'
                  ? t('Could not save. Check the fields and try again.')
                  : null;

    const blurSave = () => void persist({ type, title, instructions });

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
                        {t('Changes save automatically.')}
                    </p>
                </div>
                <p
                    className="font-data text-xs text-muted-foreground"
                    aria-live="polite"
                >
                    {saveLabel}
                </p>
            </div>

            <FieldGroup className="gap-4">
                <Field data-disabled={!canEdit}>
                    <FieldLabel htmlFor={`builder-type-${documentRequest.id}`}>
                        {t('Type')}
                    </FieldLabel>
                    <Select
                        value={type}
                        disabled={!canEdit}
                        onValueChange={(value) => {
                            const nextType = value as QuestionnaireItemType;
                            setType(nextType);
                            void persist({
                                type: nextType,
                                title,
                                instructions,
                            });
                        }}
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
                                        value={definition.value}
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

                <Field data-disabled={!canEdit} data-invalid={Boolean(error)}>
                    <FieldLabel htmlFor={`builder-title-${documentRequest.id}`}>
                        {t('Label')}
                    </FieldLabel>
                    <Input
                        id={`builder-title-${documentRequest.id}`}
                        value={title}
                        disabled={!canEdit}
                        autoComplete="off"
                        onChange={(event) => setTitle(event.target.value)}
                        onBlur={blurSave}
                        aria-invalid={Boolean(error)}
                    />
                    <InputError message={error ?? undefined} />
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
                        value={instructions}
                        disabled={!canEdit}
                        onChange={(event) =>
                            setInstructions(event.target.value)
                        }
                        onBlur={blurSave}
                        placeholder={t(
                            'Optional help text shown to the client…',
                        )}
                    />
                </Field>
            </FieldGroup>
        </aside>
    );
}
