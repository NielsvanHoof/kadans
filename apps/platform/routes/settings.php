<?php

declare(strict_types=1);

use App\Accounts\Http\Controllers\Localization\LocaleController;
use App\Accounts\Http\Controllers\Localization\TimezoneController;
use App\Accounts\Http\Controllers\Profile\ProfileController;
use App\Accounts\Http\Controllers\Security\SecurityController;
use App\Integrations\Http\Controllers\OAuth\IntegrationsSettingsController;
use App\Tenancy\Http\Middleware\InitializeTenantFromSession;
use App\Tenancy\Http\Middleware\SetPermissionTeamContext;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Keep the active firm in session context so AppLayout sidebar still shows
// Dossiers / Clients while browsing account settings.
Route::middleware([
    'auth',
    'verified',
    InitializeTenantFromSession::class,
    SetPermissionTeamContext::class,
])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware([
    'auth',
    'verified',
    InitializeTenantFromSession::class,
    SetPermissionTeamContext::class,
])->group(function (): void {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/integrations', [IntegrationsSettingsController::class, 'edit'])
        ->name('integrations.edit');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/language', [LocaleController::class, 'edit'])->name('language.edit');
    Route::patch('settings/language', [LocaleController::class, 'update'])->name('language.update');
    // Shares the language screen — locale and timezone are one regional concern.
    Route::patch('settings/timezone', [TimezoneController::class, 'update'])->name('timezone.update');
});

Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');
