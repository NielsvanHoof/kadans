import type { DocumentRequest } from '@/features/document-requests/types';
import type { AccessGrant } from '@/features/portal/types';

/**
 * Panels of the dossier overview workbench, synced to ?tab= so a panel can be
 * linked to and survives a reload.
 *
 * Ordered the way the work flows: what the client still owes, how they get in,
 * what we recorded about them, what leaves the building.
 */
export const DOSSIER_PANELS = [
    'waiting',
    'access',
    'recordings',
    'exports',
] as const;

export type DossierPanel = (typeof DOSSIER_PANELS)[number];

export function isDossierPanel(value: string): value is DossierPanel {
    return DOSSIER_PANELS.includes(value as DossierPanel);
}

/** Awaiting the client: never answered, or sent back for correction. */
function isAwaitingClient(request: DocumentRequest): boolean {
    return request.status === 'pending' || request.status === 'rejected';
}

/**
 * Items the client is actually being asked for right now.
 *
 * Shared by the tab count and the panel body so the two can never disagree.
 * `is_applicable` is part of the predicate, not an afterthought: the review
 * queue, the `review_summary` counters, and `has_outstanding_client_items` all
 * count applicable rows only, so a count taken on status alone reports work the
 * client cannot see and the rest of the page does not acknowledge.
 */
export function waitingDocumentRequests(
    documentRequests: DocumentRequest[],
): DocumentRequest[] {
    return documentRequests.filter(
        (request) => isAwaitingClient(request) && request.is_applicable,
    );
}

/**
 * Unanswered items the client is *not* being asked for — hidden by a visibility
 * rule, or optional and left blank.
 *
 * Kept visible rather than filtered away: "why does the form have nine fields
 * and the panel four" is a question staff will ask, and the answer is auditable.
 */
export function notCurrentlyAskedDocumentRequests(
    documentRequests: DocumentRequest[],
): DocumentRequest[] {
    return documentRequests.filter(
        (request) => isAwaitingClient(request) && !request.is_applicable,
    );
}

export function validAccessGrants(accessGrants: AccessGrant[]): AccessGrant[] {
    return accessGrants.filter((grant) => grant.is_valid);
}
