/**
 * Client portal types.
 *
 * Payload shapes are generated from the PHP Data objects; only the refined
 * PortalDocumentRequest (structured `configuration`) is layered on top.
 */
import type { PortalDocumentRequest } from '@/features/document-requests/types';

/** Magic-link grant for client portal access. */
export type AccessGrant = App.Dossiers.Data.DossierAccessGrantData;

/** Dossier payload for the client portal page. */
export type PortalDossier = Omit<
    App.ClientPortal.Data.PortalDossierData,
    'document_requests'
> & {
    document_requests: PortalDocumentRequest[];
};

/** Progress counters on the portal overview. */
export type PortalProgress = App.ClientPortal.Data.PortalProgressData;
