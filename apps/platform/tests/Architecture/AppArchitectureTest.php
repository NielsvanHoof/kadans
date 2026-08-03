<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel preset
|--------------------------------------------------------------------------
|
| Start from Pest's Laravel conventions, then ignore namespaces we extend
| with project-specific rules below.
|
*/

arch()->preset()->laravel()->ignoring([
    'App\Accounts',
    'App\AudioProcessing',
    'App\Audit',
    'App\ClientPortal',
    'App\Clients',
    'App\Dossiers',
    'App\DocumentProcessing',
    'App\Platform',
    'App\Questionnaires',
    'App\Reporting',
    'App\Shared',
    'App\Tenancy',
    'App\WorkspaceNotifications',
]);

/*
|--------------------------------------------------------------------------
| Framework-agnostic hardening presets
|--------------------------------------------------------------------------
|
| These add generic PHP quality and security guardrails on top of the
| Laravel-specific preset and our project-specific preset.
|
*/

arch()->preset()->php();
arch()->preset()->security();

/*
|--------------------------------------------------------------------------
| Qompose preset
|--------------------------------------------------------------------------
|
| Domain-oriented conventions used across this application.
|
*/

arch()->preset()->qompose();
