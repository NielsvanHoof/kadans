import DossierChaseLine from '@/features/dossiers/follow-up/dossier-chase-line';
import type { Dossier } from '@/features/dossiers/types';

/**
 * Lower row of the dossier state band: the chase, as facts only.
 *
 * The act of chasing moved to the Waiting panel, which lists the very items a
 * reminder is about. Its trigger was gated on `has_outstanding_client_items` —
 * true in exactly the cases that panel has rows — so nothing lost a home, and
 * the band is now purely a readout of where the dossier stands.
 */
export default function DossierFollowUpCard({ dossier }: { dossier: Dossier }) {
    return (
        <div className="border-t border-ledger-rule">
            <DossierChaseLine dossier={dossier} />
        </div>
    );
}
