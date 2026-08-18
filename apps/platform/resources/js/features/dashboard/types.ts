/** Workspace dashboard metric counters plus weekly activity for trends. */
export type WorkspaceDashboardMetrics = App.Reporting.Data.DashboardMetricsData;

/** Recently updated dossiers, as sent by the dashboard controller. */
export type DashboardRecentDossier =
    App.Reporting.Data.DashboardRecentDossierData;

/** The signed-in user's own queue — deferred, never cached. */
export type DashboardMyWork = App.Reporting.Data.DashboardMyWorkData;

/** One dated dossier on the personal agenda. */
export type DashboardAgendaItem = App.Reporting.Data.DashboardAgendaItemData;

/** Clients who have stalled: never opened, gone quiet, or link expiring. */
export type DashboardFollowUpSignals =
    App.Reporting.Data.DashboardFollowUpSignalsData;

/** One dossier inside a follow-up group. */
export type DashboardFollowUpItem =
    App.Reporting.Data.DashboardFollowUpItemData;

/** Failed and stuck background runs. */
export type DashboardProcessingHealth =
    App.Reporting.Data.DashboardProcessingHealthData;

/** One failed or stuck pipeline run. */
export type DashboardPipelineFailure =
    App.Reporting.Data.DashboardPipelineFailureData;

/** One weekly bucket of documents received. */
export type DashboardTrendPoint = App.Reporting.Data.DashboardTrendPointData;
