import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { QuestionnaireOption } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/** A choice field needs at least two options, and no more than the backend allows. */
const MINIMUM_OPTIONS = 2;
const MAXIMUM_OPTIONS = 50;

/**
 * Add, rename, reorder, and remove the options of a choice field.
 *
 * Option ids are generated once here and never regenerated, so renaming a label
 * leaves existing visibility rules and stored answers pointing at the same option.
 */
export function FieldOptionsEditor({
    options,
    canEdit,
    onChange,
    onSaveDraft,
}: {
    options: QuestionnaireOption[];
    canEdit: boolean;
    onChange: (options: QuestionnaireOption[]) => void;
    /** Called for changes with no blur event of their own (add, remove, reorder). */
    onSaveDraft: () => void;
}) {
    const { t } = useTranslation();

    const moveOption = (index: number, direction: -1 | 1) => {
        const target = index + direction;

        if (target < 0 || target >= options.length) {
            return;
        }

        const next = [...options];
        const [moved] = next.splice(index, 1);
        next.splice(target, 0, moved);
        onChange(next);
        onSaveDraft();
    };

    const renameOption = (index: number, label: string) => {
        onChange(
            options.map((option, candidateIndex) =>
                candidateIndex === index ? { ...option, label } : option,
            ),
        );
    };

    const removeOption = (index: number) => {
        onChange(
            options.filter((_, candidateIndex) => candidateIndex !== index),
        );
        onSaveDraft();
    };

    const addOption = () => {
        onChange([
            ...options,
            {
                id: crypto.randomUUID(),
                label: t('Option :number', { number: options.length + 1 }),
            },
        ]);
        onSaveDraft();
    };

    return (
        <FieldGroup className="gap-2">
            <FieldLabel>{t('Options')}</FieldLabel>
            <FieldDescription className="text-xs">
                {t(
                    'Stable option ids keep conditions working if you rename a label.',
                )}
            </FieldDescription>
            {options.map((option, index) => (
                <div key={option.id} className="flex items-center gap-1.5">
                    <Input
                        value={option.label}
                        disabled={!canEdit}
                        autoComplete="off"
                        aria-label={t('Option :number', { number: index + 1 })}
                        onChange={(event) =>
                            renameOption(index, event.target.value)
                        }
                        onBlur={onSaveDraft}
                    />
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="size-8 shrink-0"
                        disabled={!canEdit || index === 0}
                        aria-label={t('Move option up')}
                        onClick={() => moveOption(index, -1)}
                    >
                        <ArrowUp aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="size-8 shrink-0"
                        disabled={!canEdit || index === options.length - 1}
                        aria-label={t('Move option down')}
                        onClick={() => moveOption(index, 1)}
                    >
                        <ArrowDown aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="size-8 shrink-0"
                        disabled={!canEdit || options.length <= MINIMUM_OPTIONS}
                        aria-label={t('Remove option')}
                        onClick={() => removeOption(index)}
                    >
                        <Trash2 aria-hidden="true" />
                    </Button>
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!canEdit || options.length >= MAXIMUM_OPTIONS}
                className="self-start"
                onClick={addOption}
            >
                <Plus aria-hidden="true" />
                {t('Add option')}
            </Button>
        </FieldGroup>
    );
}

/** Two starter options, so a new choice field is immediately valid. */
export function buildDefaultOptions(
    t: (key: string, replacements?: Record<string, number>) => string,
): QuestionnaireOption[] {
    return [1, 2].map((number) => ({
        id: crypto.randomUUID(),
        label: t('Option :number', { number }),
    }));
}
