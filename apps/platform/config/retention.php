<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Archived client and dossier retention
    |--------------------------------------------------------------------------
    |
    | Soft-deleted clients and dossiers are permanently purged after this many
    | days, including stored files and related activity log entries.
    |
    */
    'archived_days' => (int) env('RETENTION_ARCHIVED_DAYS', 1095),

    /*
    |--------------------------------------------------------------------------
    | Activity log retention
    |--------------------------------------------------------------------------
    |
    | Entries older than this are removed by the scheduled activitylog:clean
    | command. Kept in sync with archived record retention by default.
    |
    */
    'activity_log_days' => (int) env('RETENTION_ACTIVITY_LOG_DAYS', env('RETENTION_ARCHIVED_DAYS', 1095)),

    /*
    |--------------------------------------------------------------------------
    | Failed queue jobs
    |--------------------------------------------------------------------------
    */
    'failed_jobs_hours' => (int) env('RETENTION_FAILED_JOBS_HOURS', 168),

    /*
    |--------------------------------------------------------------------------
    | Queue job batches
    |--------------------------------------------------------------------------
    */
    'job_batches_hours' => (int) env('RETENTION_JOB_BATCHES_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Database notifications
    |--------------------------------------------------------------------------
    */
    'notifications_days' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Client portal access grants
    |--------------------------------------------------------------------------
    |
    | Expired or revoked grants older than this are removed by model:prune.
    |
    */
    'portal_grants_days' => (int) env('RETENTION_PORTAL_GRANTS_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Workspace member invitations
    |--------------------------------------------------------------------------
    |
    | Accepted, revoked, or expired invitations older than this are removed by
    | model:prune.
    |
    */
    'member_invitations_days' => (int) env('RETENTION_MEMBER_INVITATIONS_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Data-subject requests
    |--------------------------------------------------------------------------
    |
    | `response_days` sets the due date on a logged access or erasure request —
    | GDPR allows one month, so 30 days by default.
    |
    | `export_hours` bounds how long a generated export archive stays
    | downloadable. It is a copy of everything held about one person, so it must
    | not outlive the request it answers. Longer than a dossier export (which
    | expires in minutes) because the firm has to get it to the client.
    |
    */
    'data_subject_response_days' => (int) env('RETENTION_DATA_SUBJECT_RESPONSE_DAYS', 30),
    'data_subject_export_hours' => (int) env('RETENTION_DATA_SUBJECT_EXPORT_HOURS', 72),

];
