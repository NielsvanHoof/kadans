import type { WorkspaceNotificationsSummary } from '@/features/notifications/types';
import type { Firm } from '@/features/workspaces/types';
import type { Auth } from '@/types/auth';

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
            translations: Record<string, string>;
            auth: Auth;
            workspaces: Firm[];
            current_firm: Firm | null;
            can_manage_members: boolean;
            can_view_roles: boolean;
            can_view_pipelines: boolean;
            support_session: SupportSessionShared | null;
            notifications: WorkspaceNotificationsSummary | null | undefined;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
