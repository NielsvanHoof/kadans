import type { WorkspaceNotificationsSummary } from '@/features/notifications/types';
import type { Firm } from '@/features/workspaces/types';
import type { Auth } from '@/types/auth';
import type { TenantPermissionMap } from '@/types/generated/tenant-permissions';

type AvailableLocale = {
    code: string;
    label: string;
};

type SupportSessionShared = {
    tenant: Firm;
    reason: string;
    expires_at: string;
};

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: string;
            available_locales: AvailableLocale[];
            /** Resolved IANA zone for presenting instants. Format from this, never from the browser's own zone. */
            timezone: string;
            translations: Record<string, string>;
            auth: Auth;
            workspaces: Firm[];
            current_firm: Firm | null;
            /** Every registered tenant permission, resolved for the current firm. */
            permissions: Partial<TenantPermissionMap>;
            /** Firm-wide settings for the active workspace. */
            firm_settings:
                | import('@/hooks/use-firm-settings').FirmSettings
                | null;
            support_session: SupportSessionShared | null;
            notifications: WorkspaceNotificationsSummary | null | undefined;
            sidebar_open: boolean;
            [key: string]: unknown;
        };
    }
}
