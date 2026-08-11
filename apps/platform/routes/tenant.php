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
 * Staff workspace routes on {tenant}.{base_domain}, split per domain under
 * routes/tenant/. Middleware and name prefix are applied once here.
 */
$tenantDomain = '{tenant}.'.config('tenancy-hosts.base_domain');

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
    ->domain($tenantDomain)
    ->where(['tenant' => '[a-z0-9]([a-z0-9-]*[a-z0-9])?'])
    ->name('workspaces.')
    ->group(function (): void {
        require __DIR__.'/tenant/reporting.php';
        require __DIR__.'/tenant/clients.php';
        require __DIR__.'/tenant/members.php';
        require __DIR__.'/tenant/roles.php';
        require __DIR__.'/tenant/questionnaires.php';
        require __DIR__.'/tenant/dossiers.php';
        require __DIR__.'/tenant/document-requests.php';
        require __DIR__.'/tenant/audio-recordings.php';
        require __DIR__.'/tenant/access-grants.php';
        require __DIR__.'/tenant/notifications.php';
        require __DIR__.'/tenant/data-subject-requests.php';
    });
