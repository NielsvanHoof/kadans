import type { DossierStatus } from '@/features/dossiers/types';

/**
 * English keys for dossier statuses. Pass through `t()` for display.
 */
export const DOSSIER_STATUS_LABELS: Record<DossierStatus, string> = {
    draft: 'Draft',
    awaiting_client: 'Awaiting client',
    in_review: 'In review',
    completed: 'Completed',
};

/**
 * Resolve a translated label for a dossier status.
 */
export function dossierStatusLabel(
    status: DossierStatus,
    t: (key: string) => string,
): string {
    return t(DOSSIER_STATUS_LABELS[status]);
}
