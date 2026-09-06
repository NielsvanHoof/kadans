import { usePage } from '@inertiajs/react';

export type FirmSettings = App.Tenancy.Data.FirmSharedSettingsData;

export type FirmAiSettings = FirmSettings['ai'];

function useFirmSettings(): FirmSettings | null {
    return usePage().props.firm_settings as FirmSettings | null;
}

export function useAiFeatureEnabled(feature: keyof FirmAiSettings): boolean {
    return useFirmSettings()?.ai[feature] === true;
}

/**
 * The firm's house reminder rhythm, or null when it has not set one. Prefills a
 * new dossier; never overrides an interval already chosen on a dossier.
 */
export function useDefaultReminderIntervalDays(): number | null {
    return useFirmSettings()?.reminders.default_interval_days.effective ?? null;
}
