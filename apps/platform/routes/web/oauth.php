<?php

declare(strict_types=1);

use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Http\Controllers\OAuth\OAuthController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * Per-user OAuth (Microsoft first). Guest login + authenticated link/unlink.
 * {provider} is constrained to OAuthProvider values.
 */
$providerConstraint = implode('|', OAuthProvider::values());

Route::middleware(['web', 'throttle:oauth'])->group(function () use ($providerConstraint): void {
    Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])
        ->where('provider', $providerConstraint)
        ->name('oauth.redirect');

    Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])
        ->where('provider', $providerConstraint)
        ->name('oauth.callback');
});

Route::middleware(['web', 'auth', 'verified', 'throttle:oauth'])->group(function () use ($providerConstraint): void {
    Route::delete('auth/{provider}', [OAuthController::class, 'destroy'])
        ->where('provider', $providerConstraint)
        ->middleware(RequirePassword::class)
        ->name('oauth.destroy');
});
