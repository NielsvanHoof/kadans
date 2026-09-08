/**
 * Dossier domain types.
 *
 * Payload shapes are generated from the PHP Data objects (see
 * resources/js/types/generated/app.d.ts) and only aliased here, so the names
 * used across the feature stay short and import sites do not churn. Add a new
 * payload field in the PHP DTO, not here.
 */
import type { DocumentRequest } from '@/features/document-requests/types';

export type DossierStatus = App.Dossiers.Enums.DossierStatus;

/** Review progress counts shared by overview and review surfaces. */
export type DossierReviewSummary =
    App.Dossiers.Data.Lifecycle.DossierReviewSummaryData;

/**
 * Payloads carrying questionnaire items swap in the refined DocumentRequest,
 * whose `configuration` is structured rather than a free-form JSON map.
 */
type WithItems<T> = Omit<T, 'document_requests'> & {
    document_requests: DocumentRequest[];
};

/** Full dossier payload for the staff overview (show) page. */
export type Dossier = WithItems<App.Dossiers.Data.Lifecycle.DossierShowData>;

/** Lean dossier payload for the questionnaire builder page. */
export type DossierBuilder =
    WithItems<App.Dossiers.Data.Builder.DossierBuilderData>;

/** Exclusive builder edit lease returned with the builder page / lock endpoints. */
export type DossierBuilderLock =
    App.Dossiers.Data.Builder.DossierBuilderLockData;

/** Lean dossier payload for the review queue page. */
export type DossierReview =
    WithItems<App.Dossiers.Data.DocumentRequests.DossierReviewData>;

/** Fields needed by the review progress / complete card. */
export type DossierWorkflowProgress = Pick<
    DossierReview,
    'id' | 'status' | 'review_summary' | 'ready_to_complete'
>;

export type DossierExport = App.Dossiers.Data.Exports.DossierExportData;

export type Pipeline = App.Pipelines.Data.PipelineData;

export type PipelineStep = App.Pipelines.Data.PipelineStepData;

export type DossierSummary = App.Dossiers.Data.Lifecycle.DossierIndexRowData;

export type ArchivedDossierSummary =
    App.Dossiers.Data.Lifecycle.ArchivedDossierRowData;

export type EditableDossier = Pick<
    Dossier,
    | 'id'
    | 'title'
    | 'reference'
    | 'client'
    | 'due_date'
    | 'reminder_interval_days'
>;

export type EditableDossierWithResponsibility = EditableDossier & {
    responsible_user_id: number | null;
};

/** Client option for the create-dossier form. */
export type DossierClientOption = App.Shared.Data.PersonOptionData;

export type ResponsibleStaffOption = App.Shared.Data.PersonOptionData;

/** Re-exported so dossier surfaces can keep importing item rows from here. */
export type { DocumentRequest };
