import DossierWorkflowLinks from '@/features/dossiers/show/dossier-workflow-links';
import type { Dossier } from '@/features/dossiers/types';

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
