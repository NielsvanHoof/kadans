<?php

declare(strict_types=1);

use App\Tenancy\Http\Controllers\ActiveTenantController;
use App\Tenancy\Http\Controllers\FirmController;
use App\Tenancy\Http\Controllers\WorkspaceOnboardingController;
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
