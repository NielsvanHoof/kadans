import { useCurrentWorkspace } from '@/hooks/use-current-workspace';

/**
 * Route parameters every staff answer form posts to.
 * Kept here so each form resolves the tenant the same way.
 */
export function useAnswerRouteParameters(
    dossierId: number,
    documentRequestId: number,
) {
    const currentWorkspace = useCurrentWorkspace();

    return {
        tenant: currentWorkspace,
        dossier: dossierId,
        documentRequest: documentRequestId,
    };
}
