/** Workspace dashboard metric counters plus weekly activity for trends. */
export type WorkspaceDashboardMetrics = {
    clients: number;
    open_dossiers: number;
    needs_review: number;
    awaiting_client: number;
    overdue: number;
    in_review: number;
    submitted_document_requests: number;
    outstanding_document_requests: number;
    open_dossiers_this_week: number;
    open_dossiers_last_week: number;
    needs_review_this_week: number;
    needs_review_last_week: number;
    awaiting_client_this_week: number;
    awaiting_client_last_week: number;
};
