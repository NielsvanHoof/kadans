import { createContext, type ReactNode, useContext, useMemo } from 'react';
import type { DossierStatus } from '@/features/dossiers/types';

/**
 * Staff dossier capabilities derived once from page props and dossier status.
 * Avoids threading permission flags through every card and review row.
 */
export type DossierCapabilities = {
    canManage: boolean;
    canReview: boolean;
    canDownload: boolean;
    /** Edit dossier metadata (title, client, due date, etc.). */
    canEditDetails: boolean;
    canSendReminder: boolean;
    /** Staff may upload or answer on behalf of the client (review/builder fallback). */
    canStaffUploadFallback: boolean;
    /** Invite the client after the questionnaire has at least one field. */
    canCreateInvite: boolean;
    /** Upload an audio recording (server already accounts for dossier status). */
    canUploadAudio: boolean;
    canDownloadAudio: boolean;
    dossierStatus: DossierStatus;
};

/** Raw capability flags from the server plus dossier context for derivation. */
export type DossierCapabilitiesInput = {
    canManage: boolean;
    canReview: boolean;
    canDownload: boolean;
    canEditDetails: boolean;
    canSendReminder: boolean;
    canUploadAudio: boolean;
    canDownloadAudio: boolean;
    dossierStatus: DossierStatus;
    hasFormItems: boolean;
};

export function deriveDossierCapabilities(
    input: DossierCapabilitiesInput,
): DossierCapabilities {
    const canEditStructure =
        input.canManage && input.dossierStatus !== 'completed';

    return {
        canManage: input.canManage,
        canReview: input.canReview && input.dossierStatus !== 'completed',
        canDownload: input.canDownload,
        canEditDetails: input.canEditDetails,
        canSendReminder: input.canSendReminder,
        canStaffUploadFallback: canEditStructure,
        canCreateInvite: canEditStructure && input.hasFormItems,
        canUploadAudio: input.canUploadAudio,
        canDownloadAudio: input.canDownloadAudio,
        dossierStatus: input.dossierStatus,
    };
}

const DossierCapabilitiesContext = createContext<DossierCapabilities | null>(
    null,
);

export function DossierPermissionsProvider({
    canManage,
    canReview,
    canDownload,
    canEditDetails,
    canSendReminder,
    canUploadAudio,
    canDownloadAudio,
    dossierStatus,
    hasFormItems,
    children,
}: DossierCapabilitiesInput & { children: ReactNode }) {
    const value = useMemo(
        () =>
            deriveDossierCapabilities({
                canManage,
                canReview,
                canDownload,
                canEditDetails,
                canSendReminder,
                canUploadAudio,
                canDownloadAudio,
                dossierStatus,
                hasFormItems,
            }),
        [
            canManage,
            canReview,
            canDownload,
            canEditDetails,
            canSendReminder,
            canUploadAudio,
            canDownloadAudio,
            dossierStatus,
            hasFormItems,
        ],
    );

    return (
        <DossierCapabilitiesContext.Provider value={value}>
            {children}
        </DossierCapabilitiesContext.Provider>
    );
}

export function useDossierCapabilities(): DossierCapabilities {
    const value = useContext(DossierCapabilitiesContext);

    if (value === null) {
        throw new Error(
            'useDossierCapabilities must be used within DossierPermissionsProvider.',
        );
    }

    return value;
}
