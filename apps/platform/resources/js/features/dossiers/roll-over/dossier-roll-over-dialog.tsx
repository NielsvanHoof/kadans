import { parseDate } from '@internationalized/date';
import { Form } from '@inertiajs/react';
import { CalendarSync } from 'lucide-react';
import { useId, useState } from 'react';
import DossierRollOverController from '@/actions/App/Dossiers/Http/Controllers/Lifecycle/DossierRollOverController';
import DatePicker from '@/components/forms/date-picker';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { Spinner } from '@/components/ui/spinner';
import type { Dossier } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/** Sentinel for "this dossier tracks no period", which is a valid choice. */
const NO_PERIOD_VALUE = '__no_period__';

/**
 * Start the next period from this dossier.
 *
 * Menu-driven, so the dialog is controlled and `DialogTrigger` is skipped
 * entirely — the same shape ConfirmDestroyDialog documents. Wrapping content in
 * a trigger without a pressable child mounts a second modal and warns.
 *
 * The period and due date are prefilled one year ahead. The reference is left
 * empty on purpose: dossiers are unique on (tenant_id, reference), so the new
 * period always needs its own and the server rejects a duplicate.
 */
export default function DossierRollOverDialog({
    dossier,
    open,
    onOpenChange,
}: {
    dossier: Pick<
        Dossier,
        'id' | 'title' | 'period_year' | 'period_label' | 'due_date'
    >;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [carryAnswers, setCarryAnswers] = useState(true);
    const [periodYear, setPeriodYear] = useState(() =>
        dossier.period_year === null
            ? NO_PERIOD_VALUE
            : (dossier.period_year + 1).toString(),
    );
    const titleFieldId = useId();
    const titleErrorId = useId();
    const referenceFieldId = useId();
    const referenceErrorId = useId();
    const periodYearFieldId = useId();
    const periodYearErrorId = useId();
    const periodLabelFieldId = useId();
    const dueDateFieldId = useId();
    const carryAnswersFieldId = useId();

    const nextPeriodYear =
        dossier.period_year === null ? null : dossier.period_year + 1;
    const selectableYears = periodYearWindow(dossier.period_year);
    const nextDueDate = addOneYear(dossier.due_date);
    const nextPeriodLabel =
        dossier.period_label === null || dossier.period_year === null
            ? dossier.period_label
            : dossier.period_label.replace(
                  String(dossier.period_year),
                  String(nextPeriodYear),
              );

    return (
        <Dialog
            isOpen={open}
            onOpenChange={onOpenChange}
            className="sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{t('Start the next period')}</DialogTitle>
                <DialogDescription>
                    {t(
                        'Creates a new dossier for this client with the same questions. Uploaded files stay with the period they belong to.',
                    )}
                </DialogDescription>
            </DialogHeader>

            <Form
                {...DossierRollOverController.store.form({
                    tenant: currentWorkspace,
                    dossier: dossier.id,
                })}
                onSuccess={() => onOpenChange(false)}
            >
                {({ errors, processing }) => (
                    <>
                        {/* Always posted, so unticking means false rather than absent. */}
                        <input
                            type="hidden"
                            name="carry_answers"
                            value={carryAnswers ? '1' : '0'}
                        />

                        <FieldGroup className="gap-4">
                            <Field data-invalid={Boolean(errors.title)}>
                                <FieldLabel htmlFor={titleFieldId}>
                                    {t('Title')}
                                </FieldLabel>
                                <Input
                                    id={titleFieldId}
                                    name="title"
                                    defaultValue={
                                        nextPeriodLabel ?? dossier.title
                                    }
                                    {...fieldErrorAriaProps(
                                        titleErrorId,
                                        errors.title,
                                    )}
                                />
                                <InputError
                                    id={titleErrorId}
                                    message={errors.title}
                                />
                            </Field>

                            <Field data-invalid={Boolean(errors.reference)}>
                                <FieldLabel htmlFor={referenceFieldId}>
                                    {t('Reference')}
                                </FieldLabel>
                                <Input
                                    id={referenceFieldId}
                                    name="reference"
                                    {...fieldErrorAriaProps(
                                        referenceErrorId,
                                        errors.reference,
                                    )}
                                />
                                <FieldDescription>
                                    {t(
                                        'Must differ from the reference of the period you are rolling over.',
                                    )}
                                </FieldDescription>
                                <InputError
                                    id={referenceErrorId}
                                    message={errors.reference}
                                />
                            </Field>

                            <Field
                                className="min-w-0"
                                data-invalid={Boolean(errors.period_year)}
                            >
                                <FieldLabel htmlFor={periodYearFieldId}>
                                    {t('Period')}
                                </FieldLabel>
                                {/* React Aria Select ignores FieldLabel+htmlFor
                                    (LabelContext is nulled when htmlFor is set). */}
                                <Select
                                    selectedKey={periodYear}
                                    onSelectionChange={(key) =>
                                        setPeriodYear(String(key ?? ''))
                                    }
                                    aria-label={t('Period')}
                                >
                                    <SelectTrigger
                                        id={periodYearFieldId}
                                        className="w-full min-w-0"
                                        aria-invalid={Boolean(
                                            errors.period_year,
                                        )}
                                    >
                                        <SelectValue>
                                            {periodYear === NO_PERIOD_VALUE
                                                ? t('No period')
                                                : periodYear}
                                        </SelectValue>
                                    </SelectTrigger>
                                    <SelectContent className="bg-background space-y-1 p-1">
                                        <SelectGroup>
                                            <SelectItem id={NO_PERIOD_VALUE}>
                                                {t('No period')}
                                            </SelectItem>
                                            {selectableYears.map((year) => (
                                                <SelectItem
                                                    key={year}
                                                    id={year.toString()}
                                                >
                                                    {year.toString()}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                {/*
                                 * Bounded to the years around the source, so the
                                 * server-side 1900-2200 range is unreachable from
                                 * the UI and stays purely a defence in depth.
                                 */}
                                <input
                                    type="hidden"
                                    name="period_year"
                                    value={
                                        periodYear === NO_PERIOD_VALUE
                                            ? ''
                                            : periodYear
                                    }
                                />
                                <InputError
                                    id={periodYearErrorId}
                                    message={errors.period_year}
                                />
                            </Field>

                            <Field data-invalid={Boolean(errors.period_label)}>
                                <FieldLabel htmlFor={periodLabelFieldId}>
                                    {t('Period name')}
                                </FieldLabel>
                                <Input
                                    id={periodLabelFieldId}
                                    name="period_label"
                                    defaultValue={nextPeriodLabel ?? ''}
                                    placeholder={t('For example: FY2026')}
                                />
                                <InputError message={errors.period_label} />
                            </Field>

                            <Field
                                className="min-w-0"
                                data-invalid={Boolean(errors.due_date)}
                            >
                                <FieldLabel htmlFor={dueDateFieldId}>
                                    {t('Due date')}
                                </FieldLabel>
                                <DatePicker
                                    id={dueDateFieldId}
                                    name="due_date"
                                    defaultValue={nextDueDate}
                                    invalid={Boolean(errors.due_date)}
                                />
                                <InputError message={errors.due_date} />
                            </Field>

                            <Field orientation="horizontal" className="gap-2">
                                <Checkbox
                                    id={carryAnswersFieldId}
                                    isSelected={carryAnswers}
                                    onChange={(checked) =>
                                        setCarryAnswers(checked === true)
                                    }
                                />
                                <FieldLabel htmlFor={carryAnswersFieldId}>
                                    {t(
                                        'Carry the answers from the previous period',
                                    )}
                                </FieldLabel>
                            </Field>
                            <FieldDescription>
                                {t(
                                    'The client sees their previous answers and only has to confirm or change them. Requested documents always start empty.',
                                )}
                            </FieldDescription>
                            <InputError message={errors.carry_answers} />
                        </FieldGroup>

                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => onOpenChange(false)}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button type="submit" isDisabled={processing}>
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <CalendarSync aria-hidden="true" />
                                )}
                                {processing
                                    ? t('Starting…')
                                    : t('Start next period')}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </Form>
        </Dialog>
    );
}

/**
 * Years offered for the new period, oldest first.
 *
 * Anchored on the period being rolled over rather than on today: a firm
 * catching up on a late 2023 book year needs 2024 at the top of the list, not
 * the current year. One year back is included because the same source is
 * sometimes rolled into a sibling deliverable for the period it already covers.
 */
function periodYearWindow(sourcePeriodYear: number | null): number[] {
    const anchor = sourcePeriodYear ?? new Date().getFullYear();

    return Array.from({ length: 7 }, (_unused, offset) => anchor - 1 + offset);
}

/**
 * The same calendar day one year on, as a `YYYY-MM-DD` string.
 *
 * Goes through CalendarDate rather than string surgery on the year: `add`
 * clamps 29 February to the 28th in a non-leap year, where slicing the year
 * out would produce a day that does not exist. Returns '' for a source with no
 * due date, which is what DatePicker treats as empty.
 */
function addOneYear(dueDate: string | null): string {
    if (dueDate === null) {
        return '';
    }

    try {
        return parseDate(dueDate).add({ years: 1 }).toString();
    } catch {
        return '';
    }
}
