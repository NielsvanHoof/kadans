<?php

declare(strict_types=1);

use App\Tenancy\Http\Controllers\Invitations\AcceptTenantInvitationController;
use Illuminate\Support\Facades\Route;

/*
 * Workspace member invitation accept/register — outside tenant membership
 * middleware because invitees are not active members yet.
 */
Route::get('invitations/{token}', [AcceptTenantInvitationController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('invitations.show');

Route::post('invitations/{token}/accept', [AcceptTenantInvitationController::class, 'accept'])
    ->middleware(['auth', 'throttle:20,1'])
    ->name('invitations.accept');

Route::post('invitations/{token}/register', [AcceptTenantInvitationController::class, 'register'])
    ->middleware(['guest', 'throttle:20,1'])
    ->name('invitations.register');
