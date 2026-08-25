/**
 * Lower readout of the chase as facts only.
 *
 * The act of chasing lives on the Waiting panel, beside the items a reminder
 * is about. This wrapper is a facts-only ribbon used by tests and the rail.
 */
import DossierChaseLine from '@/features/dossiers/follow-up/dossier-chase-line';
import type { Dossier } from '@/features/dossiers/types';

export default function DossierFollowUpCard({ dossier }: { dossier: Dossier }) {
    return <DossierChaseLine dossier={dossier} />;
}
