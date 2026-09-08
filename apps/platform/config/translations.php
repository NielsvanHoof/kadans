<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Source path to owning domain
    |--------------------------------------------------------------------------
    |
    | `lang:organize` reads this to decide which domain owns a key, from the
    | files that reference it. Longest matching prefix wins.
    |
    | A domain's copy is one file, but its code is two trees: 925 keys are used
    | only from resources/js, 370 only from app/, and 60 from both. So the
    | mapping cannot be inferred from the directory a file sits in — the JS
    | halves have to be named explicitly against the domain that owns them.
    |
    | Anything unmatched falls to TranslationDomains::FallbackDomain, which is
    | how resources/js/{components,hooks,lib,layouts} copy lands in Shared.
    |
    */

    'domains' => [
        'app/Accounts' => 'Accounts',
        'app/Audit' => 'Audit',
        'app/AudioProcessing' => 'Dossiers',
        'app/ClientPortal' => 'ClientPortal',
        'app/Clients' => 'Clients',
        // Owns only generic status words (Pending, Failed, Completed,
        // Processing) that several domains share, so it holds no copy of its
        // own and its processing strings sit with the pipeline that runs them.
        'app/DocumentProcessing' => 'Pipelines',
        'app/Dossiers' => 'Dossiers',
        'app/Integrations' => 'Integrations',
        'app/Pipelines' => 'Pipelines',
        'app/Platform' => 'Platform',
        'app/Questionnaires' => 'Questionnaires',
        'app/Reporting' => 'Reporting',
        'app/Tenancy' => 'Tenancy',
        'app/WorkspaceNotifications' => 'WorkspaceNotifications',

        'resources/js/features/audio-recordings' => 'Dossiers',
        'resources/js/features/audit' => 'Audit',
        'resources/js/features/clients' => 'Clients',
        'resources/js/features/dashboard' => 'Reporting',
        'resources/js/features/document-search' => 'Reporting',
        'resources/js/features/data-subject-requests' => 'Platform',
        'resources/js/features/document-requests' => 'Questionnaires',
        'resources/js/features/dossiers' => 'Dossiers',
        'resources/js/features/integrations' => 'Integrations',
        'resources/js/features/invitations' => 'Tenancy',
        'resources/js/features/legal' => 'Platform',
        'resources/js/features/media' => 'Reporting',
        'resources/js/features/members' => 'Tenancy',
        'resources/js/features/notifications' => 'WorkspaceNotifications',
        'resources/js/features/pipelines' => 'Pipelines',
        'resources/js/features/platform' => 'Platform',
        'resources/js/features/portal' => 'ClientPortal',
        'resources/js/features/questionnaires' => 'Questionnaires',
        'resources/js/features/roles' => 'Tenancy',
        'resources/js/features/security' => 'Accounts',
        'resources/js/features/settings' => 'Platform',
        'resources/js/features/workspaces' => 'Tenancy',

        'resources/js/pages/auth' => 'Accounts',
        'resources/js/pages/firms' => 'Platform',
        'resources/js/pages/invitations' => 'Tenancy',
        'resources/js/pages/legal' => 'Platform',
        'resources/js/pages/onboarding' => 'Platform',
        'resources/js/pages/platform' => 'Platform',
        'resources/js/pages/portal' => 'ClientPortal',
        'resources/js/pages/settings' => 'Platform',
        'resources/js/pages/workspaces' => 'Tenancy',
        'resources/js/pages/workspaces/audio-recordings' => 'Dossiers',
        'resources/js/pages/workspaces/clients' => 'Clients',
        'resources/js/pages/workspaces/data-subject-requests' => 'Platform',
        'resources/js/pages/workspaces/dossiers' => 'Dossiers',
        'resources/js/pages/workspaces/questionnaires' => 'Questionnaires',
        'resources/js/pages/workspaces/uploaded-documents' => 'Dossiers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pinned keys
    |--------------------------------------------------------------------------
    |
    | Keys that are live but have no literal call site, so the scanners cannot
    | place them. Each names the symbol that produces it.
    |
    | `lang:organize` fails when a pinned key is absent from the dictionary, so
    | a pin cannot rot silently into an orphan. These also serve as the
    | allowlist for the orphan gate.
    |
    */

    'pins' => [
        // App\Accounts\Enums\Locale::label() — rendered client-side via t(label).
        'English' => 'Accounts',
        'Nederlands' => 'Accounts',

        // FetchPipelineIndexQuery: __(Str::headline($status->value)).
        'Pending' => 'Pipelines',
        'Running' => 'Pipelines',
        'Completed' => 'Pipelines',
        'Failed' => 'Pipelines',
        'Stuck' => 'Pipelines',
        'Cancelled' => 'Pipelines',

        // resources/views/vendor/mail/{html,text}/message.blade.php — views
        // are outside the scanner roots (app/, resources/js), so the call
        // site is invisible to placement.
        'All rights reserved.' => 'Shared',

        // bootstrap/app.php PostTooLarge handler — bootstrap/ is outside the
        // scanner roots, so this __() call is invisible to placement.
        'That request was too large to process. Try again with less data.' => 'Shared',

        // resources/js/features/document-requests/item-types/definitions.ts —
        // the map is questionnaireItemTypeDefinitionsByType, which the
        // *_LABELS|labels scanner cannot match. Resolved as
        // t(definition.label|description|defaultTitle).
        'File upload' => 'Questionnaires',
        'Text answer' => 'Questionnaires',
        'Long text' => 'Questionnaires',
        'Date' => 'Questionnaires',
        'Number' => 'Questionnaires',
        'Yes / no' => 'Questionnaires',
        'Single choice' => 'Questionnaires',
        'Multiple choice' => 'Questionnaires',
        'Ask the client to upload a document.' => 'Questionnaires',
        'Collect a short written answer.' => 'Questionnaires',
        'Collect a longer written answer.' => 'Questionnaires',
        'Ask the client to pick a date.' => 'Questionnaires',
        'Collect a numeric answer.' => 'Questionnaires',
        'Ask a yes or no confirmation.' => 'Questionnaires',
        'Let the client choose one option.' => 'Questionnaires',
        'Let the client choose one or more options.' => 'Questionnaires',
        'New file upload' => 'Questionnaires',
        'New text answer' => 'Questionnaires',
        'New long text answer' => 'Questionnaires',
        'New date question' => 'Questionnaires',
        'New number question' => 'Questionnaires',
        'New yes / no question' => 'Questionnaires',
        'New single choice question' => 'Questionnaires',
        'New multiple choice question' => 'Questionnaires',

        // ocr-processing-copy.ts — returns English, caller does t(message).
        'Still scanning your document. This usually takes a few minutes — results will appear when ready.' => 'Questionnaires',
        'Analyzing document structure (forms and tables)…' => 'Questionnaires',
        'Analysis is still running. Results will update shortly.' => 'Questionnaires',
        'Document analysis is still running. Results will update shortly.' => 'Questionnaires',

        // dossier-stage.ts STAGE_HINTS — map name is not *_LABELS|labels.
        'Build the client form by dragging fields into place.' => 'Dossiers',
        'Send a portal link so the client can complete the form. Then continue to Review.' => 'Dossiers',
        'Check submissions, approve or request changes, then complete the dossier.' => 'Dossiers',

        // dossier-export-card.tsx — ternary string literals inside t().
        'Ready' => 'Dossiers',
        'Preparing' => 'Dossiers',

        // FailedQueueJobsCheck / StuckPipelinesCheck — Spatie Health calls
        // trans() on Result::$notificationMessage with the check meta. The
        // English key is passed untranslated; wrapping ok()/failed() in __()
        // would look up the Dutch sentence as a key.
        'No jobs failed permanently in the last :within_minutes minutes.' => 'Platform',
        ':failed_jobs job(s) failed permanently in the last :within_minutes minutes.' => 'Platform',
        'No stuck pipelines.' => 'Platform',
        ':stuck_pipelines pipeline(s) are stuck and need attention.' => 'Platform',
    ],
];
