<?php

declare(strict_types=1);

use App\Clients\Http\Controllers\ClientController;
use App\Dossiers\Http\Controllers\AudioRecordingController;
use App\Dossiers\Http\Controllers\DocumentRequestController;
use App\Dossiers\Http\Controllers\DocumentRequestReviewController;
use App\Dossiers\Http\Controllers\DossierBuilderLockController;
use App\Dossiers\Http\Controllers\DossierController;
use App\Dossiers\Http\Controllers\DossierExportController;
use App\Dossiers\Http\Controllers\UploadedDocumentController;
use App\Platform\Http\Controllers\DataSubjectRequestController;
use App\Questionnaires\Http\Controllers\QuestionnaireTemplateController;
use App\Questionnaires\Http\Controllers\QuestionnaireTemplateItemController;
use App\Reporting\Http\Controllers\PipelineController;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Http\Controllers\AcceptTenantInvitationController;
use App\Tenancy\Http\Controllers\TenantInvitationController;

/*
|--------------------------------------------------------------------------
| Controller actions
|--------------------------------------------------------------------------
|
| Controllers may expose the REST verbs freely. Anything else has to be declared
| here, per controller.
|
| This replaced a single global allowlist in the arch preset, which had grown to
| 18 names and had two problems: adding one method to one controller silently
| permitted that name on every controller, and the list recorded no intent — it
| read as a changelog rather than a contract.
|
| The methods themselves are deliberately not split into single-action __invoke
| controllers: `archived`/`restore`/`bulkArchive`/`bulkRestore` are one resource's
| archive lifecycle, and `download`/`preview`/`live` are representations of one
| document. Splitting them would fragment cohesive code and churn the generated
| Wayfinder imports for no behavioural gain.
|
| Assertions are exact both ways: an undeclared method fails, and so does a
| declaration for a method that no longer exists.
|
*/

/** REST verbs plus framework hooks, allowed on any controller without declaration. */
const REST_CONTROLLER_ACTIONS = [
    '__construct',
    '__invoke',
    'index',
    'show',
    'create',
    'store',
    'edit',
    'update',
    'destroy',
    'middleware',
];

/** @var array<class-string, list<string>> controller => its non-REST actions */
const CONTROLLER_NON_REST_ACTIONS = [
    // Archive lifecycle of a single resource.
    ClientController::class => [
        'archived', 'bulkArchive', 'bulkRestore', 'restore',
    ],
    DossierController::class => [
        'archived', 'builder', 'bulkArchive', 'bulkRestore', 'restore', 'review',
    ],

    // Two-step compliance workflow: log, then fulfil or refuse; the archive
    // download is gated separately because it is a copy of one whole person.
    DataSubjectRequestController::class => [
        'download', 'fulfil', 'refuse',
    ],

    // Bulk operations on the questionnaire item collection.
    DocumentRequestController::class => [
        'applyTemplate', 'reorder', 'sync',
    ],
    QuestionnaireTemplateItemController::class => [
        'reorder',
    ],
    QuestionnaireTemplateController::class => [
        'copy',
    ],

    // Alternative representations / transports of one resource.
    UploadedDocumentController::class => [
        'download', 'live', 'preview',
    ],
    AudioRecordingController::class => [
        'download', 'live',
    ],
    DossierExportController::class => ['download'],

    // A processing run is not a REST resource: `download` streams its payload
    // and `retry` re-queues the run in place rather than creating a new one.
    PipelineController::class => ['cancel', 'download', 'retry'],

    // Staff answering on the client's behalf, alongside the review decision.
    DocumentRequestReviewController::class => ['answer'],

    // Lease renewal on the builder lock.
    DossierBuilderLockController::class => ['heartbeat'],

    // Token-credential invitation flow.
    AcceptTenantInvitationController::class => [
        'accept', 'register',
    ],
    TenantInvitationController::class => ['resend'],
];

/**
 * @return list<class-string>
 */
function applicationControllers(): array
{
    $controllers = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            dirname(__DIR__, 2).'/app',
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (! str_contains((string) $file->getPathname(), '/Http/Controllers/')) {
            continue;
        }

        $relative = str_replace(
            [dirname(__DIR__, 2).'/app/', '/', '.php'],
            ['App\\', '\\', ''],
            (string) $file->getPathname(),
        );

        if (class_exists($relative) && is_subclass_of($relative, Controller::class)) {
            $controllers[] = $relative;
        }
    }

    sort($controllers);

    return $controllers;
}

/**
 * @return list<string>
 */
function nonRestActionsOf(string $controller): array
{
    $actions = [];

    foreach (new ReflectionClass($controller)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        // Only methods the controller itself declares; inherited helpers are not actions.
        if ($method->getDeclaringClass()->getName() !== $controller) {
            continue;
        }

        if (in_array($method->getName(), REST_CONTROLLER_ACTIONS, true)) {
            continue;
        }

        $actions[] = $method->getName();
    }

    sort($actions);

    return $actions;
}

test('every non-REST controller action is declared for its own controller', function (): void {
    $controllers = applicationControllers();

    expect($controllers)->not->toBeEmpty();

    $actual = [];

    foreach ($controllers as $controller) {
        $actions = nonRestActionsOf($controller);

        if ($actions !== []) {
            $actual[$controller] = $actions;
        }
    }

    $expected = CONTROLLER_NON_REST_ACTIONS;
    ksort($expected);
    ksort($actual);

    expect($actual)->toBe(
        $expected,
        'A controller action outside the REST verbs must be declared in '
        .'CONTROLLER_NON_REST_ACTIONS for that specific controller, and stale '
        .'declarations must be removed.',
    );
});

test('declared controllers all exist and extend the base controller', function (): void {
    foreach (array_keys(CONTROLLER_NON_REST_ACTIONS) as $controller) {
        expect(class_exists($controller))->toBeTrue("[{$controller}] does not exist.")
            ->and(is_subclass_of($controller, Controller::class))->toBeTrue();
    }
});
