<?php

declare(strict_types=1);

use App\Accounts\Http\Middleware\RequireTwoFactorAuthentication;
use App\Tenancy\Http\Middleware\InitializeTenantFromSession;
use App\Tenancy\Http\Middleware\SetPermissionTeamContext;
use Illuminate\Support\Facades\Route;

/*
 * Authenticated (non-workspace) routes, split per domain under routes/web/.
 * These resolve the tenant from the session instead of the URL.
 *
 * The root `/` is the global dashboard (auth required), so guests are
 * redirected to login by the auth middleware.
 */
Route::middleware([
    'auth',
    'verified',
    // After SetPermissionTeamContext: the `by_permission` mode reads the user's
    // role in the session's workspace, which is not resolved before then.
    InitializeTenantFromSession::class,
    SetPermissionTeamContext::class,
    RequireTwoFactorAuthentication::class,
])->group(function (): void {
    require __DIR__.'/web/reporting.php';
    require __DIR__.'/web/tenancy.php';
    require __DIR__.'/web/platform.php';
});

require __DIR__.'/settings.php';
require __DIR__.'/web/invitations.php';
require __DIR__.'/web/oauth.php';
require __DIR__.'/web/legal.php';
