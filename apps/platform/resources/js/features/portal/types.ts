/**
 * Client portal types.
 *
 * Payload shapes are generated from the PHP Data objects; only the refined
 * PortalDocumentRequest (structured `configuration`) is layered on top.
 */
import type { PortalDocumentRequest } from '@/features/document-requests/types';

/** Magic-link grant for client portal access. */
export type AccessGrant = App.Dossiers.Data.Lifecycle.DossierAccessGrantData;

/** Dossier payload for the client portal page. */
export type PortalDossier = Omit<
    App.ClientPortal.Data.Workspace.PortalDossierData,
    'document_requests'
> & {
    document_requests: PortalDocumentRequest[];
};

/** One dossier on the client's list of everything their firm is waiting for. */
export type PortalDossierSummary =
    App.ClientPortal.Data.Workspace.PortalDossierSummaryData;

/** Payload for the landing page a client-level link opens. */
export type PortalLanding =
    App.ClientPortal.Data.Workspace.PortalLandingPageData;

/** The client a portal link belongs to. */
export type PortalClient = App.ClientPortal.Data.Workspace.PortalClientData;

/** Progress counters on the portal overview. */
export type PortalProgress = App.ClientPortal.Data.Workspace.PortalProgressData;

/** The firm, plus the staff member the client can actually reach. */
export type PortalFirm = App.ClientPortal.Data.Workspace.PortalFirmData;

/** The client's own session deadlines, for the pre-expiry warning. */
export type PortalSession = App.ClientPortal.Data.Session.PortalSessionData;
