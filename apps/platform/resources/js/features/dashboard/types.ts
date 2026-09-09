/** Workspace dashboard metric counters plus weekly activity for trends. */
export type WorkspaceDashboardMetrics =
    App.Reporting.Data.Dashboard.DashboardMetricsData;

/** Recently updated dossiers, as sent by the dashboard controller. */
export type DashboardRecentDossier =
    App.Reporting.Data.Dashboard.DashboardRecentDossierData;

/** The signed-in user's own queue — deferred, never cached. */
export type DashboardMyWork = App.Reporting.Data.Dashboard.DashboardMyWorkData;

/** One dated dossier on the personal agenda. */
export type DashboardAgendaItem =
    App.Reporting.Data.Dashboard.DashboardAgendaItemData;

/** Clients who have stalled: never opened, gone quiet, or link expiring. */
export type DashboardFollowUpSignals =
    App.Reporting.Data.Dashboard.DashboardFollowUpSignalsData;

/** One dossier inside a follow-up group. */
export type DashboardFollowUpItem =
    App.Reporting.Data.Dashboard.DashboardFollowUpItemData;

/** Failed and stuck background runs. */
export type DashboardProcessingHealth =
    App.Reporting.Data.Dashboard.DashboardProcessingHealthData;

/** One failed or stuck pipeline run. */
export type DashboardPipelineFailure =
    App.Reporting.Data.Dashboard.DashboardPipelineFailureData;

/** One weekly bucket of documents received. */
export type DashboardTrendPoint =
    App.Reporting.Data.Dashboard.DashboardTrendPointData;
