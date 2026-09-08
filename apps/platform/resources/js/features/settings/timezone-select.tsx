import { router, usePage } from '@inertiajs/react';
import type { Key } from 'react-aria-components';
import TimezoneController from '@/actions/App/Accounts/Http/Controllers/Localization/TimezoneController';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { setClientCookie } from '@/lib/set-client-cookie';

type AvailableTimezone = {
    value: string;
    label: string;
};

/**
 * Timezone preference. Syncs cookie + server, mirroring LocaleTabs.
 *
 * The list is a curated shortlist rather than every IANA zone; validation stays
 * permissive so a browser-detected zone outside the list still round-trips, and
 * the server appends the current zone when it is not already present.
 */
export default function TimezoneSelect() {
    const { t } = useTranslation();
    const { timezone, available_timezones } = usePage<{
        available_timezones: AvailableTimezone[];
    }>().props;

    const updateTimezone = (nextTimezone: Key | null): void => {
        if (typeof nextTimezone !== 'string' || nextTimezone === timezone) {
            return;
        }

        setClientCookie('timezone', nextTimezone);

        router.patch(
            TimezoneController.update.url(),
            { timezone: nextTimezone },
            { preserveScroll: true },
        );
    };

    return (
        <Field>
            <FieldLabel htmlFor="timezone">{t('Timezone')}</FieldLabel>
            <Select
                name="timezone"
                // React Aria ignores FieldLabel+htmlFor, so the label must be
                // repeated here or useLabel floods the console.
                aria-label={t('Timezone')}
                // Keyed by `id` on SelectItem — `value` is inert.
                selectedKey={timezone}
                onSelectionChange={updateTimezone}
            >
                <SelectTrigger id="timezone" className="w-full sm:w-80">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent className="bg-background space-y-1 p-1">
                    <SelectGroup>
                        {available_timezones.map((option) => (
                            <SelectItem
                                key={option.value}
                                id={option.value}
                                textValue={option.label}
                            >
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
        </Field>
    );
}
