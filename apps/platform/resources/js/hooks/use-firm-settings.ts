import { usePage } from '@inertiajs/react';

export type FirmSettings = App.Tenancy.Data.TenantSettingsData;

export type FirmAiSettings = FirmSettings['ai'];

export function useAiFeatureEnabled(feature: keyof FirmAiSettings): boolean {
    const firmSettings = usePage().props.firm_settings as FirmSettings | null;

    return firmSettings?.ai[feature] === true;
}
