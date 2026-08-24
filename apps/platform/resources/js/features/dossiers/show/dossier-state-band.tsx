import DossierFollowUpCard from '@/features/dossiers/follow-up/dossier-follow-up-card';
import DossierWorkflowLinks from '@/features/dossiers/show/dossier-workflow-links';
import type { Dossier } from '@/features/dossiers/types';

/**
 * Where this dossier stands, on one sheet.
 *
 * Bounded by rules rather than boxed in a card: the page is a ledger sheet, and
 * a card border around a band that already divides itself with hairlines makes a
 * box of boxes. The rules do the dividing; nothing needs an outline.
 */
export default function DossierStateBand({
    dossier,
    onSelectInvite,
}: {
    dossier: Dossier;
    onSelectInvite: () => void;
}) {
    return (
        <section className="-mx-4 border-y border-ledger-rule md:mx-0">
            <DossierWorkflowLinks
                dossier={dossier}
                onSelectInvite={onSelectInvite}
            />
            <DossierFollowUpCard dossier={dossier} />
        </section>
    );
}
