import { useState } from 'react';
import DatePicker from '@/components/date-picker';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
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
        <fieldset className="space-y-4 rounded-lg border bg-muted/20 p-4">
            <legend className="px-1 text-sm font-semibold">
                {t('Follow-up planning')}
            </legend>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid min-w-0 gap-2">
                    <Label htmlFor="due_date">{t('Due date')}</Label>
                    <DatePicker
                        id="due_date"
                        name="due_date"
                        defaultValue={defaultDueDate}
                        invalid={Boolean(errors.due_date)}
                    />
                    <InputError message={errors.due_date} />
                </div>

                <div className="grid min-w-0 gap-2">
                    <Label htmlFor="responsible_user_id">
                        {t('Responsible staff member')}
                    </Label>
                    <Select
                        value={responsibleUserId}
                        onValueChange={setResponsibleUserId}
                    >
                        <SelectTrigger
                            id="responsible_user_id"
                            className="w-full min-w-0 overflow-hidden"
                            aria-invalid={Boolean(errors.responsible_user_id)}
                        >
                            <SelectValue placeholder={t('Unassigned')}>
                                {selectedStaffMember?.name ?? t('Unassigned')}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent className="space-y-1 bg-background p-1">
                            <SelectItem value={UNASSIGNED_VALUE}>
                                {t('Unassigned')}
                            </SelectItem>
                            {responsibleStaff.map((staffMember) => (
                                <SelectItem
                                    key={staffMember.id}
                                    value={staffMember.id.toString()}
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
                </div>
            </div>

            <div className="grid gap-2 sm:max-w-xs">
                <Label htmlFor="reminder_interval_days">
                    {t('Automatic reminder cadence')}
                </Label>
                <div className="flex items-center gap-2">
                    <Input
                        id="reminder_interval_days"
                        name="reminder_interval_days"
                        type="number"
                        min="1"
                        max="30"
                        defaultValue={defaultReminderIntervalDays ?? ''}
                    />
                    <span className="text-sm text-muted-foreground">
                        {t('days')}
                    </span>
                </div>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Leave empty to disable scheduled reminders. The cadence starts after the first client email.',
                    )}
                </p>
                <InputError message={errors.reminder_interval_days} />
            </div>
        </fieldset>
    );
}
