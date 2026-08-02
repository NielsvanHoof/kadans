import { useState } from 'react';
import DatePicker from '@/components/forms/date-picker';
import InputError from '@/components/primitives/input-error';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
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
import type { ResponsibleStaffOption } from '@/features/dossiers/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Radix Select does not allow an empty item value, so "unassigned" needs a
 * sentinel. The hidden input below maps it back to an empty string, which
 * Laravel converts to null for the nullable `responsible_user_id` column.
 */
const UNASSIGNED_VALUE = '__unassigned__';

export default function DossierFollowUpFields({
    responsibleStaff,
    defaultDueDate = '',
    defaultResponsibleUserId = null,
    defaultReminderIntervalDays = null,
    errors,
}: {
    responsibleStaff: ResponsibleStaffOption[];
    defaultDueDate?: string;
    defaultResponsibleUserId?: number | null;
    defaultReminderIntervalDays?: number | null;
    errors: Partial<Record<string, string>>;
}) {
    const { t } = useTranslation();

    // The Select is controlled so the hidden input can mirror its value.
    const [responsibleUserId, setResponsibleUserId] = useState(
        defaultResponsibleUserId?.toString() ?? UNASSIGNED_VALUE,
    );

    // Closed trigger only shows the name so long emails cannot blow out the grid column.
    const selectedStaffMember = responsibleStaff.find(
        (staffMember) => staffMember.id.toString() === responsibleUserId,
    );

    return (
        <FieldSet className="gap-4 rounded-lg border bg-muted/20 p-4">
            <FieldLegend variant="label" className="mb-0 px-1">
                {t('Follow-up planning')}
            </FieldLegend>

            <FieldGroup className="gap-4 sm:grid sm:grid-cols-2">
                <Field
                    className="min-w-0"
                    data-invalid={Boolean(errors.due_date)}
                >
                    <FieldLabel htmlFor="due_date">{t('Due date')}</FieldLabel>
                    <DatePicker
                        id="due_date"
                        name="due_date"
                        defaultValue={defaultDueDate}
                        invalid={Boolean(errors.due_date)}
                    />
                    <InputError message={errors.due_date} />
                </Field>

                <Field
                    className="min-w-0"
                    data-invalid={Boolean(errors.responsible_user_id)}
                >
                    <FieldLabel htmlFor="responsible_user_id">
                        {t('Responsible staff member')}
                    </FieldLabel>
                    <Select
                        selectedKey={responsibleUserId}
                        onSelectionChange={(key) =>
                            setResponsibleUserId(String(key ?? ''))
                        }
                    >
                        <SelectTrigger
                            id="responsible_user_id"
                            className="w-full min-w-0 overflow-hidden"
                            aria-invalid={Boolean(errors.responsible_user_id)}
                        >
                            <SelectValue>
                                {selectedStaffMember?.name ?? t('Unassigned')}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent className="space-y-1 bg-background p-1">
                            <SelectGroup>
                                <SelectItem id={UNASSIGNED_VALUE}>
                                    {t('Unassigned')}
                                </SelectItem>
                                {responsibleStaff.map((staffMember) => (
                                    <SelectItem
                                        key={staffMember.id}
                                        id={staffMember.id.toString()}
                                        textValue={staffMember.name}
                                        className="flex flex-col items-start"
                                    >
                                        <span className="font-medium">
                                            {staffMember.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {staffMember.email}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    {/* Keeps the submitted field name/format identical to the old native select. */}
                    <input
                        type="hidden"
                        name="responsible_user_id"
                        value={
                            responsibleUserId === UNASSIGNED_VALUE
                                ? ''
                                : responsibleUserId
                        }
                    />
                    <InputError message={errors.responsible_user_id} />
                </Field>
            </FieldGroup>

            <Field
                className="sm:max-w-xs"
                data-invalid={Boolean(errors.reminder_interval_days)}
            >
                <FieldLabel htmlFor="reminder_interval_days">
                    {t('Automatic reminder cadence')}
                </FieldLabel>
                <div className="flex items-center gap-2">
                    <Input
                        id="reminder_interval_days"
                        name="reminder_interval_days"
                        type="number"
                        min="1"
                        max="30"
                        defaultValue={defaultReminderIntervalDays ?? ''}
                        aria-invalid={Boolean(errors.reminder_interval_days)}
                    />
                    <span className="text-sm text-muted-foreground">
                        {t('days')}
                    </span>
                </div>
                <FieldDescription>
                    {t(
                        'Leave empty to disable scheduled reminders. The cadence starts after the first client email.',
                    )}
                </FieldDescription>
                <InputError message={errors.reminder_interval_days} />
            </Field>
        </FieldSet>
    );
}
