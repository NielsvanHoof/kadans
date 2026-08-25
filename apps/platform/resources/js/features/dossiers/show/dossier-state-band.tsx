import DossierWorkflowLinks from '@/features/dossiers/show/dossier-workflow-links';
import type { Dossier } from '@/features/dossiers/types';

/**
 * Compact workflow strip for the dossier overview.
 *
 * Chase facts (sent, opened, due) live in the context rail, beside the
 * waiting list they describe — not in a second band above it.
 */
export default function DossierStateBand({
    dossier,
    onSelectInvite,
}: {
    dossier: Dossier;
    onSelectInvite: () => void;
}) {
    return (
        <DossierWorkflowLinks
            dossier={dossier}
            onSelectInvite={onSelectInvite}
        />
    );
}
