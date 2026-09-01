<?php

declare(strict_types=1);

use App\ClientPortal\Http\Controllers\ClientPortalUploadController;
use App\Dossiers\Actions\DownloadDossierExportAction;
use App\Dossiers\Http\Controllers\AudioRecordingController;
use App\Dossiers\Http\Controllers\DossierExportController;
use App\Dossiers\Http\Controllers\UploadedDocumentController;
use App\Dossiers\Support\StoredFileResponse;
use App\Reporting\Http\Controllers\PipelineController;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    'App\Integrations',
    'App\Pipelines',
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

/*
|--------------------------------------------------------------------------
| Stored-file delivery return types
|--------------------------------------------------------------------------
|
| StoredFileResponse::serve() answers an Inertia visit with
| Inertia::location() — a 409 Illuminate\Http\Response — so every caller must
| declare a return type that admits it. Rector's NarrowWideUnionReturnTypeRector
| cannot resolve that branch through the facade and strips the type, which turns
| each of these endpoints into a TypeError on an Inertia visit. rector.php skips
| the rule for these files; this test is what notices if that skip is lost.
|
*/

test('every stored-file delivery method admits an Inertia location response', function (string $class, string $method): void {
    $returnType = new ReflectionMethod($class, $method)->getReturnType();

    expect($returnType)->not->toBeNull("{$class}::{$method}() has no return type");

    $admitsResponse = collect(
        $returnType instanceof ReflectionUnionType ? $returnType->getTypes() : [$returnType],
    )->contains(fn (ReflectionNamedType $type): bool => is_a(
        $type->getName(),
        Response::class,
        allow_string: true,
    ) && ! is_a($type->getName(), StreamedResponse::class, allow_string: true)
        && ! is_a($type->getName(), RedirectResponse::class, allow_string: true));

    expect($admitsResponse)->toBeTrue(
        "{$class}::{$method}() must keep Symfony\\Component\\HttpFoundation\\Response in its "
        .'return type, or an Inertia visit to it fatals. Did Rector narrow the union?'
    );
})->with([
    [ClientPortalUploadController::class, 'download'],
    [DownloadDossierExportAction::class, 'handle'],
    [AudioRecordingController::class, 'download'],
    [DossierExportController::class, 'download'],
    [UploadedDocumentController::class, 'download'],
    [UploadedDocumentController::class, 'preview'],
    [PipelineController::class, 'download'],
    [StoredFileResponse::class, 'serve'],
]);
