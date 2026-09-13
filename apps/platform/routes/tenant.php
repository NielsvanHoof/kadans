<?php

declare(strict_types=1);

use App\Accounts\Http\Middleware\RequireTwoFactorAuthentication;
use App\Tenancy\Http\Middleware\EnforceStrictTenantScope;
use App\Tenancy\Http\Middleware\EnsureValidTenantMembership;
use App\Tenancy\Http\Middleware\InitializeTenantFromRoute;
use App\Tenancy\Http\Middleware\SetPermissionTeamContext;
use Illuminate\Support\Facades\Route;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

/*
 * Staff workspace routes, split per domain under routes/tenant/.
 * The middleware stack, prefix, and name prefix are applied once here
 * so every domain file only declares its own routes.
 */

// Integer bindings must reject non-numeric segments before model resolution —
// otherwise Postgres raises SQLSTATE 22P02 (uncaught QueryException 500).
// Same idea as whereUuid on notification ids; keep the regex next to the group
// that owns these param names. See .ai/rules/routes.md.
foreach ([
    'client',
    'dossier',
    'export',
    'membership',
    'invitation',
    'role',
    'pipeline',
    'template',
    'item',
    'documentRequest',
    'uploadedDocument',
    'audioRecording',
    'calendarLink',
    'grant',
    'dataSubjectRequest',
] as $integerParam) {
    Route::pattern($integerParam, '[0-9]+');
}

Route::middleware([
    'web',
    'auth',
    'verified',
    InitializeTenantFromRoute::class,
    EnforceStrictTenantScope::class,
    EnsureValidTenantMembership::class,
    SetPermissionTeamContext::class,
    NeedsTenant::class,
    // Last: the `by_permission` mode needs the tenant current and the Spatie
    // team context set before it can read the user's role in this workspace.
    RequireTwoFactorAuthentication::class,
])
    ->prefix('workspaces/{tenant:slug}')
    ->name('workspaces.')
    ->group(function (): void {
        require __DIR__.'/tenant/reporting.php';
        require __DIR__.'/tenant/clients.php';
        require __DIR__.'/tenant/members.php';
        require __DIR__.'/tenant/roles.php';
        require __DIR__.'/tenant/settings.php';
        require __DIR__.'/tenant/questionnaires.php';
        require __DIR__.'/tenant/dossiers.php';
        require __DIR__.'/tenant/uploads.php';
        require __DIR__.'/tenant/document-requests.php';
        require __DIR__.'/tenant/audio-recordings.php';
        require __DIR__.'/tenant/access-grants.php';
        require __DIR__.'/tenant/notifications.php';
        require __DIR__.'/tenant/data-subject-requests.php';
    });
