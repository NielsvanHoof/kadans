import { usePage } from '@inertiajs/react';

export type FirmSettings = App.Tenancy.Data.TenantSettingsData;

export type FirmAiSettings = FirmSettings['ai'];

/**
 * Firm-wide settings for the active workspace.
 *
 * Outside a firm context (account settings, auth) the shared prop is null.
 */
export function useFirmSettings(): FirmSettings | null {
    const firmSettings = usePage().props.firm_settings as FirmSettings | null;

    return firmSettings ?? null;
}

export function useAiFeatureEnabled(feature: keyof FirmAiSettings): boolean {
    return useFirmSettings()?.ai[feature] === true;
}
