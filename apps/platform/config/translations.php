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

        // resources/views/vendor/mail/{html,text}/message.blade.php — views
        // are outside the scanner roots (app/, resources/js), so the call
        // site is invisible to placement.
        'All rights reserved.' => 'Shared',

        // resources/js/features/document-requests/item-types/definitions.ts —
        // the map is questionnaireItemTypeDefinitionsByType, which the
        // *_LABELS|labels scanner cannot match, and it is read as
        // t(definition.label).
        'File upload' => 'Questionnaires',
        'Text answer' => 'Questionnaires',
        'Long text' => 'Questionnaires',
        'Single choice' => 'Questionnaires',
        'Multiple choice' => 'Questionnaires',
    ],
];
