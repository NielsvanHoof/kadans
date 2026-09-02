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

        'resources/js/pages/audio-recordings' => 'Dossiers',
        'resources/js/pages/auth' => 'Accounts',
        'resources/js/pages/clients' => 'Clients',
        'resources/js/pages/data-subject-requests' => 'Platform',
        'resources/js/pages/dossiers' => 'Dossiers',
        'resources/js/pages/firms' => 'Platform',
        'resources/js/pages/invitations' => 'Tenancy',
        'resources/js/pages/legal' => 'Platform',
        'resources/js/pages/onboarding' => 'Platform',
        'resources/js/pages/platform' => 'Platform',
        'resources/js/pages/portal' => 'ClientPortal',
        'resources/js/pages/questionnaires' => 'Questionnaires',
        'resources/js/pages/settings' => 'Platform',
        'resources/js/pages/uploaded-documents' => 'Dossiers',
        'resources/js/pages/workspaces' => 'Tenancy',
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

        // Pending browser review: live-looking copy with no literal call
        // site today. The orphan gate allowlists them so new orphans fail
        // while these can be purged one group at a time.
        '1 event' => 'Shared',
        ':count events' => 'Shared',
        'Add' => 'Shared',
        'Add a file upload, text, date, number, or yes/no question.' => 'Shared',
        'Add request' => 'Shared',
        'Adding field…' => 'Shared',
        'Already have an account?' => 'Shared',
        'Also tracking' => 'Shared',
        'Analysis is still running. Results will update shortly.' => 'Shared',
        'Analyzing document structure (forms and tables)…' => 'Shared',
        'Append a reusable pack. Existing items are kept.' => 'Shared',
        'Ask for a file, a text answer, or a yes/no confirmation.' => 'Shared',
        'Automatic every :days days' => 'Shared',
        'Back to :stage' => 'Shared',
        'Back to dashboard' => 'Shared',
        'Build the client form by dragging fields into place.' => 'Shared',
        'Build the questionnaire, then continue to Invite.' => 'Shared',
        'Changed: :keys' => 'Shared',
        'Changes save automatically.' => 'Shared',
        'Check submissions, approve or request changes, then complete the dossier.' => 'Shared',
        'Clear' => 'Shared',
        'Client access' => 'Shared',
        'Complete each item below. Submitted items are locked while they are reviewed; if changes are requested, correct and resubmit them.' => 'Shared',
        'Could not add the field. Try again.' => 'Shared',
        'Could not delete the field. Try again.' => 'Shared',
        'Could not save field settings.' => 'Shared',
        'Could not save the new order.' => 'Shared',
        'Create account' => 'Shared',
        'Create an account' => 'Shared',
        'Document analysis is still running. Results will update shortly.' => 'Shared',
        'Documents still missing or rejected' => 'Shared',
        'Don\'t have an account?' => 'Shared',
        'Dossiers that still need attention' => 'Shared',
        'Due and owner' => 'Shared',
        'Enter your details below to create your account' => 'Shared',
        'Export dossier' => 'Shared',
        'Extra volume signals for the firm.' => 'Shared',
        'Field settings saved.' => 'Shared',
        'Fields' => 'Shared',
        'Follow-up status' => 'Shared',
        'Form order saved.' => 'Shared',
        'Google Calendar' => 'Shared',
        'Invalid credential format.' => 'Shared',
        'Invite' => 'Shared',
        'Items the client still needs to submit or update.' => 'Shared',
        'Last opened' => 'Shared',
        'Last sent' => 'Shared',
        'Loading audio recordings' => 'Shared',
        'Loading exports' => 'Shared',
        'My templates' => 'Shared',
        'Next reminder :datetime' => 'Shared',
        'Next: :item' => 'Shared',
        'Next: :stage' => 'Shared',
        'No client follow-up is needed.' => 'Shared',
        'No items yet. Apply a template or add a request.' => 'Shared',
        'Outstanding requests' => 'Shared',
        'Passkey not recognized. It may have been removed from your account.' => 'Shared',
        'Passkey registration session expired. Please try again.' => 'Shared',
        'Passkey verification session expired. Please try again.' => 'Shared',
        'People and organisations you support' => 'Shared',
        'Prepare' => 'Shared',
        'Preparing' => 'Shared',
        'Progress: :submitted / :total answered · :approved approved' => 'Shared',
        'Ready' => 'Shared',
        'Register' => 'Shared',
        'Review scanned documents, approve or request changes, then complete the dossier.' => 'Shared',
        'Save failed' => 'Shared',
        'Search :label…' => 'Shared',
        'Send a portal link so the client can complete the form. Then continue to Review.' => 'Shared',
        'Send a portal link so the client can upload. Then continue to Review.' => 'Shared',
        'Send a reminder to nudge the client.' => 'Shared',
        'Sign up' => 'Shared',
        'Still scanning your document. This usually takes a few minutes — results will appear when ready.' => 'Shared',
        'Still waiting' => 'Shared',
        'Tables' => 'Shared',
        'That request was too large to process. Try again with less data.' => 'Shared',
        'The client’s identity has been erased. Retained records were kept.' => 'Shared',
        'Unable to register passkey. Please try again.' => 'Shared',
        'Unable to register this passkey.' => 'Shared',
        'Unable to sign in with this account.' => 'Shared',
        'Unable to verify passkey. Please try again.' => 'Shared',
        'Upload progress' => 'Shared',
        'Upload the PDF you received from your employer.' => 'Shared',
        'Waiting for requested information' => 'Shared',
        'Work that needs your attention now.' => 'Shared',
        'You cannot send reminders for this dossier.' => 'Shared',
        'document' => 'Shared',
        'dossiers currently in review' => 'Shared',
        'items still with the client' => 'Shared',
        'submitted items ready for review' => 'Shared',
    ],
];
