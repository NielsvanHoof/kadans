<?php

declare(strict_types=1);

use App\Tenancy\Http\Controllers\Provisioning\WorkspaceOnboardingController;
use App\Tenancy\Http\Controllers\Settings\FirmController;
use App\Tenancy\Http\Controllers\Workspace\ActiveTenantController;
use Illuminate\Support\Facades\Route;

Route::get('firms/create', [FirmController::class, 'create'])
    ->name('firms.create');
Route::post('firms', [FirmController::class, 'store'])
    ->name('firms.store');
Route::post('firms/{tenant:slug}/activate', [ActiveTenantController::class, 'store'])
    ->name('firms.activate');
Route::get('onboarding/firm', [WorkspaceOnboardingController::class, 'create'])
    ->name('onboarding.firm.create');
Route::post('onboarding/firm', [WorkspaceOnboardingController::class, 'store'])
    ->name('onboarding.firm.store');
