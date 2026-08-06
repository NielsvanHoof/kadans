<?php

declare(strict_types=1);

use App\Audit\Enums\AuditPermission;
use App\ClientPortal\Enums\ClientAccessGrantPermission;
use App\Clients\Enums\ClientPermission;
use App\Dossiers\Enums\AudioRecordingPermission;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Pipelines\Enums\PipelinePermission;
use App\Questionnaires\Enums\QuestionnaireTemplatePermission;
use App\Tenancy\Enums\WorkspacePermission;

return [

    /*
    |--------------------------------------------------------------------------
    | Registered permission enums
    |--------------------------------------------------------------------------
    |
    | Every enum listed here must implement App\Tenancy\Contracts\TenantPermission.
    | This is the single place that knows the full permission set: the seeder,
    | the role sync command, and the support-session gate all read it through
    | App\Tenancy\Support\PermissionRegistry.
    |
    | Adding a permission to a domain means adding a case to that domain's enum.
    | Adding a whole new domain means adding one line here.
    |
    */

    'enums' => [
        DossierPermission::class,
        DocumentRequestPermission::class,
        UploadedDocumentPermission::class,
        AudioRecordingPermission::class,
        ClientPermission::class,
        ClientAccessGrantPermission::class,
        QuestionnaireTemplatePermission::class,
        PipelinePermission::class,
        AuditPermission::class,
        WorkspacePermission::class,
    ],
];
